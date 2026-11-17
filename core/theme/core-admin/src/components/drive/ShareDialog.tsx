import { useCallback, useEffect, useState } from 'react'
import { Box, Button, Checkbox, Chip, CircularProgress, FormControlLabel, IconButton, OutlinedInput, Switch, Tooltip, Typography } from '@mui/material'
import {
	AddLinkOutlined, BlockOutlined, CasinoOutlined, ContentCopyOutlined, DeleteOutlineOutlined, EditOutlined, LockOutlined, NotificationsActiveOutlined,
} from '@mui/icons-material'
import { t } from '../../i18n'
import { useToast } from '../Toast'
import { DriveDialog, DriveRow } from './DriveDialogs'
import { driveApi, driveDay, errorMessage, type DriveLink } from './driveApi'

// days of a new link (voted: 7)
const DEFAULT_DAYS = 7

const STATES: Record<DriveLink['state'], {label: () => string, color: string, bg: string}> = {
	ok: {label: () => t('Active'), color: 'var(--u-green)', bg: 'var(--u-green-bg)'},
	expired: {label: () => t('Expired'), color: 'var(--u-orange)', bg: 'var(--u-orange-bg)'},
	revoked: {label: () => t('Revoked'), color: 'var(--u-red)', bg: 'var(--u-red-bg)'},
	used: {label: () => t('Used up'), color: 'var(--u-orange)', bg: 'var(--u-orange-bg)'},
	gone: {label: () => t('Not available'), color: 'var(--u-muted)', bg: 'var(--u-border-soft)'},
	locked: {label: () => t('Locked'), color: 'var(--u-red)', bg: 'var(--u-red-bg)'},
}

export function LinkState({state}: {state: DriveLink['state']})
{

	const s = STATES[state]
	return <Chip label={s.label()} size="small" sx={{height: 22, fontSize: 12, fontWeight: 600, color: s.color, bgcolor: s.bg}} />

}

// Y-m-d of today + days, local time
const day = (days: number) => {

	const d = new Date()
	d.setDate(d.getDate() + days)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

}

// password of 12 characters without the ambiguous ones (0 O 1 l I)
export function generatePassword(length = 12): string
{

	const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
	const values = crypto.getRandomValues(new Uint32Array(length))
	return Array.from(values, (v) => chars[v % chars.length]).join('')

}

/**
 * text copied: navigator.clipboard (https or localhost), a hidden textarea otherwise
 */
export async function copyText(text: string): Promise<boolean>
{

	try
	{

		await navigator.clipboard.writeText(text)
		return true

	}
	catch
	{

		const area = document.createElement('textarea')
		area.value = text
		area.style.position = 'fixed'
		area.style.opacity = '0'
		document.body.appendChild(area)
		area.select()
		const done = document.execCommand('copy')
		area.remove()
		return done

	}

}

// text copied for a link: the address, the password on a second line when it is known
export const linkText = (url: string, password: string) => password ? `${url}\n${t('Password: [PASSWORD]', {PASSWORD: password})}` : url

type Form = {never: boolean, expires_at: string, max: string, password: 'keep' | 'none' | 'set', value: string, notify: boolean, note: string}

/**
 * share links of a file: its links (state, copy, edit, revoke, delete) and the form of a new link or of the link edited
 * new link (voted): expires in 7 days, unlimited downloads, no password, generate button, link and password copied at once
 */
// form of a new link
const blank = (): Form => ({never: false, expires_at: day(DEFAULT_DAYS), max: '', password: 'none', value: '', notify: false, note: ''})

export default function ShareDialog({file, edit = null, onClose, onChanged}: {file: {id: number, name: string}, edit?: DriveLink | null, onClose: () => void, onChanged?: () => void})
{

	const toast = useToast()
	const [links, setLinks] = useState<DriveLink[] | null>(null)
	const [allowed, setAllowed] = useState(false)
	const [error, setError] = useState('')
	const [errors, setErrors] = useState<Record<string, string>>({})
	const [busy, setBusy] = useState(false)
	const [editing, setEditing] = useState<DriveLink | null>(edit)
	const [form, setForm] = useState<Form>(blank)
	// link just created or changed with its password in clear (only known now)
	const [created, setCreated] = useState<{link: DriveLink, password: string} | null>(null)

	const load = useCallback(() => {

		driveApi.fileLinks(file.id).then((r) => { setLinks(r.links); setAllowed(r.allowed) }).catch((e) => setError(errorMessage(e)))

	}, [file.id])

	useEffect(load, [load])

	// form of the link edited, or of a new one
	useEffect(() => {

		setErrors({})
		if(editing)setForm({never: !editing.expires_at, expires_at: editing.expires_at || day(DEFAULT_DAYS), max: editing.max_downloads ? String(editing.max_downloads) : '', password: editing.password ? 'keep' : 'none', value: '', notify: editing.notify, note: editing.note})
		else setForm(blank())

	}, [editing])

	const submit = async () => {

		setBusy(true)
		setErrors({})
		setError('')
		const password = (form.password === 'set') ? form.value : ''
		const data = {expires_at: form.never ? '' : form.expires_at, max_downloads: parseInt(form.max, 10) || 0, notify: form.notify, note: form.note.trim(), ...((form.password === 'keep') ? {} : {password})}

		try
		{

			const r = editing ? await driveApi.updateLink(editing.id, data) : await driveApi.createLink(file.id, data)
			setCreated({link: r.link, password})
			setEditing(null)
			setForm(blank())
			load()
			onChanged?.()

		}
		catch(e)
		{

			const fields = (e as {error?: {fields?: Record<string, string[]>}}).error?.fields
			if(fields)setErrors(Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, v[0]])))
			else setError(errorMessage(e))

		}
		finally
		{

			setBusy(false)

		}

	}

	const copy = async (url: string, password = '') => {

		const ok = await copyText(linkText(url, password))
		toast(ok ? (password ? t('Link and password copied') : t('Link copied')) : t('The link cannot be copied'), ok ? 'success' : 'error')

	}

	const act = async (run: () => Promise<unknown>, message: string) => {

		try
		{

			await run()
			toast(message)
			load()
			onChanged?.()

		}
		catch(e)
		{

			toast(errorMessage(e), 'error')

		}

	}

	const today = day(0)

	return (
		<DriveDialog open title={t('Share “[NAME]”', {NAME: file.name})} onClose={onClose} width="md"
			actions={allowed ? <>
				{editing && <Button color="inherit" onClick={() => setEditing(null)} sx={{mr: 'auto'}}>{t('New link instead')}</Button>}
				<Button variant="contained" startIcon={busy ? <CircularProgress size={16} color="inherit" /> : (editing ? undefined : <AddLinkOutlined />)} disabled={busy || (form.password === 'set' && !form.value)} onClick={submit}>
					{editing ? t('Save the link') : t('Create the link')}
				</Button>
			</> : undefined}>

			{error && <Box role="alert" sx={{mb: 2, px: 2, py: 1.5, borderRadius: '6px', bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 14}}>{error}</Box>}

			{/* link created or changed: address and password copied at once */}
			{created && (
				<Box role="status" sx={{mb: 2.5, p: 2, borderRadius: '8px', bgcolor: 'var(--u-green-bg)', color: 'var(--u-green)', display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap'}}>
					<Box sx={{flex: 1, minWidth: 260}}>
						<Box sx={{fontWeight: 600, mb: 0.5}}>{t('Link ready')}</Box>
						<Box sx={{fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: 13, color: 'var(--u-text)', overflowWrap: 'anywhere'}}>{created.link.url}</Box>
						{created.password && <Box sx={{mt: 0.5, fontSize: 13, color: 'var(--u-text2)'}}>{t('Password: [PASSWORD]', {PASSWORD: created.password})}</Box>}
					</Box>
					<Button variant="contained" color="success" startIcon={<ContentCopyOutlined />} onClick={() => copy(created.link.url, created.password)}>
						{created.password ? t('Copy the link and the password') : t('Copy the link')}
					</Button>
				</Box>
			)}

			{/* links of the file */}
			{links === null && !error && <Box sx={{display: 'flex', justifyContent: 'center', py: 3}}><CircularProgress size={24} /></Box>}
			{links && links.length > 0 && (
				<Box sx={{mb: 2.5}}>
					<Typography sx={{fontSize: 12, fontWeight: 600, color: 'var(--u-muted)', textTransform: 'uppercase', letterSpacing: '.04em', mb: 1}}>{t('Links of this file')}</Typography>
					<Box sx={{border: '1px solid var(--u-border)', borderRadius: '8px', overflow: 'hidden'}}>
						{links.map((l) => (
							<Box key={l.id} sx={{display: 'flex', alignItems: 'center', gap: 1.25, px: 1.5, py: 1, borderTop: '1px solid var(--u-border-soft)', '&:first-of-type': {borderTop: 0}, bgcolor: (editing?.id === l.id) ? 'var(--u-accent-bg)' : 'transparent'}}>
								<LinkState state={l.state} />
								<Box sx={{flex: 1, minWidth: 0}}>
									<Box sx={{fontSize: 13, fontFamily: 'ui-monospace, Menlo, Consolas, monospace', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: (l.state === 'ok') ? 'var(--u-text)' : 'var(--u-muted)'}}>{l.url}</Box>
									<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 12, color: 'var(--u-muted)', minWidth: 0}}>
										{l.note && <Box component="span" title={l.note} sx={{fontWeight: 600, color: 'var(--u-text2)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', minWidth: 0}}>{l.note}</Box>}
										<span>{l.max_downloads ? t('[N] / [MAX] downloads', {N: l.downloads, MAX: l.max_downloads}) : t('[N] downloads', {N: l.downloads})}</span>
										<span>{l.expires_at ? t('until [DATE]', {DATE: driveDay(l.expires_at)}) : t('no expiration')}</span>
										{l.password && <Tooltip title={t('Password')}><LockOutlined sx={{fontSize: 14}} /></Tooltip>}
										{l.notify && <Tooltip title={t('Notified at each download')}><NotificationsActiveOutlined sx={{fontSize: 14}} /></Tooltip>}
										{l.by && <span>{t('by [NAME]', {NAME: l.by.name})}</span>}
									</Box>
								</Box>
								<Tooltip title={t('Copy the link')}><span><IconButton size="small" aria-label={t('Copy the link')} disabled={l.state !== 'ok'} onClick={() => copy(l.url)}><ContentCopyOutlined sx={{fontSize: 18}} /></IconButton></span></Tooltip>
								<Tooltip title={t('Edit')}><span><IconButton size="small" aria-label={t('Edit')} disabled={l.state === 'revoked' || l.state === 'gone'} onClick={() => { setCreated(null); setEditing(l) }}><EditOutlined sx={{fontSize: 18}} /></IconButton></span></Tooltip>
								{l.state !== 'revoked'
									? <Tooltip title={t('Revoke')}><IconButton size="small" aria-label={t('Revoke')} onClick={() => act(() => driveApi.revokeLink(l.id), t('Link revoked'))}><BlockOutlined sx={{fontSize: 18}} /></IconButton></Tooltip>
									: <Tooltip title={t('Delete')}><IconButton size="small" aria-label={t('Delete')} onClick={() => act(() => driveApi.deleteLink(l.id), t('Link deleted'))}><DeleteOutlineOutlined sx={{fontSize: 18}} /></IconButton></Tooltip>}
							</Box>
						))}
					</Box>
				</Box>
			)}

			{/* form of a new link or of the link edited */}
			{links && !allowed && <Box sx={{px: 2, py: 1.5, borderRadius: '6px', bgcolor: 'var(--u-orange-bg)', color: 'var(--u-orange)', fontSize: 14}}>{t('You cannot create a link here: no write right, or the folder refuses the share links.')}</Box>}
			{links && allowed && (
				<Box>
					<Typography sx={{fontSize: 12, fontWeight: 600, color: 'var(--u-muted)', textTransform: 'uppercase', letterSpacing: '.04em', mb: 1}}>{editing ? t('Edit the link') : t('New link')}</Typography>
					<DriveRow id="link-expires" label={t('Expires on')} error={errors.expires_at}>
						<Box sx={{display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap'}}>
							<OutlinedInput id="link-expires" type="date" value={form.expires_at} disabled={form.never} inputProps={{min: today}} onChange={(e) => setForm({...form, expires_at: e.target.value})} sx={{width: 170, fontSize: 14}} error={!!errors.expires_at} />
							{[7, 30, 90].map((d) => <Button key={d} size="small" color="inherit" disabled={form.never} onClick={() => setForm({...form, expires_at: day(d)})} sx={{minWidth: 0, color: 'var(--u-text3)'}}>{t('[N] days', {N: d})}</Button>)}
							<FormControlLabel control={<Checkbox size="small" checked={form.never} onChange={(e) => setForm({...form, never: e.target.checked})} />} label={t('Never')} sx={{m: 0, '& .MuiFormControlLabel-label': {fontSize: 14}}} />
						</Box>
					</DriveRow>
					<DriveRow id="link-max" label={t('Max downloads')} help={t('Empty = unlimited')} error={errors.max_downloads}>
						<OutlinedInput id="link-max" value={form.max} placeholder={t('Unlimited')} onChange={(e) => setForm({...form, max: e.target.value.replace(/\D/g, '').slice(0, 6)})} inputProps={{inputMode: 'numeric', style: {textAlign: 'center'}}} sx={{width: 170, fontSize: 14}} error={!!errors.max_downloads} />
					</DriveRow>
					<DriveRow id="link-password" label={t('Password')} help={(form.password === 'keep') ? t('A password protects this link: leave it, change it or remove it') : t('6 characters at least, to give to the recipient')} error={errors.password}>
						{(form.password === 'keep')
							? <Box sx={{display: 'flex', gap: 1, alignItems: 'center', minHeight: 34}}>
								<LockOutlined sx={{fontSize: 18, color: 'var(--u-text3)'}} />
								<Button size="small" variant="outlined" color="inherit" onClick={() => setForm({...form, password: 'set', value: generatePassword()})}>{t('Change')}</Button>
								<Button size="small" color="inherit" onClick={() => setForm({...form, password: 'none', value: ''})}>{t('Remove')}</Button>
							</Box>
							: <Box sx={{display: 'flex', gap: 1, alignItems: 'center'}}>
								<OutlinedInput id="link-password" value={form.value} placeholder={t('None')} autoComplete="off" onChange={(e) => setForm({...form, value: e.target.value, password: e.target.value ? 'set' : 'none'})}
									inputProps={{maxLength: 100, spellCheck: false}} sx={{flex: 1, maxWidth: 320, fontSize: 14, fontFamily: 'ui-monospace, Menlo, Consolas, monospace'}} error={!!errors.password} />
								<Button variant="outlined" color="inherit" startIcon={<CasinoOutlined />} onClick={() => setForm({...form, password: 'set', value: generatePassword()})} sx={{height: 34, borderColor: 'var(--u-border-strong)', color: 'var(--u-text2)'}}>{t('Generate')}</Button>
							</Box>}
					</DriveRow>
					<DriveRow id="link-notify" label={t('Notify me')} help={t('In-app and by email at each download')}>
						<Switch id="link-notify" checked={form.notify} onChange={(e) => setForm({...form, notify: e.target.checked})} sx={{ml: -1}} />
					</DriveRow>
					<DriveRow id="link-note" label={t('Note')} help={t('For you only: lists, notification and email, never shown to the recipient')} error={errors.note}>
						<OutlinedInput id="link-note" value={form.note} placeholder={t('Ex: client 4521')} autoComplete="off" onChange={(e) => setForm({...form, note: e.target.value})}
							inputProps={{maxLength: 100}} sx={{width: '100%', maxWidth: 420, fontSize: 14}} error={!!errors.note} />
					</DriveRow>
				</Box>
			)}
		</DriveDialog>
	)

}
