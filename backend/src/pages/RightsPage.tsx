import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { Box, Button, ButtonBase, Checkbox, CircularProgress, MenuItem, Select, Typography } from '@mui/material'
import { SaveOutlined } from '@mui/icons-material'
import { api, ApiException, dataChanged, type RightsPayload } from '../api'
import { t } from '../i18n'
import { MenuIcon } from '../components/icons'
import { useToast } from '../components/Toast'

const BORDER = '#e6e8ee'

/**
 * rights of a group (module right): one card per menu, one row per plugin, one switch per action, saved at once
 */
export default function RightsPage({icon}: {icon: string})
{

	const toast = useToast()
	const navigate = useNavigate()
	const [params] = useSearchParams()
	const [data, setData] = useState<RightsPayload | null>(null)
	const [rights, setRights] = useState<Set<string>>(new Set())
	const [error, setError] = useState('')
	const [saving, setSaving] = useState(false)
	const group = params.get('group') ?? ''

	useEffect(() => {

		setError('')
		api<RightsPayload>(`right/${group ? `?group=${encodeURIComponent(group)}` : ''}`)
			.then((payload) => {

				setData(payload)
				setRights(new Set(payload.rights))

			})
			.catch((e) => setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))

	}, [group])

	const dirty = useMemo(() => !!data && (data.rights.length !== rights.size || data.rights.some((r) => !rights.has(r))), [data, rights])

	if(!data)return <Box sx={{display: 'flex', justifyContent: 'center', py: 8}}>{error ? <Typography color="error">{error}</Typography> : <CircularProgress />}</Box>

	const keys = (plugin: {id: number, actions: string[]}) => plugin.actions.map((a) => `${plugin.id}:${a}`)

	// check or uncheck a set of rights (an action, a plugin, a menu)
	const set = (list: string[], on: boolean) => {

		const next = new Set(rights)
		list.forEach((k) => on ? next.add(k) : next.delete(k))
		setRights(next)

	}

	const save = async () => {

		setSaving(true)

		try
		{

			await api(`right/${data.group}/`, {method: 'PUT', body: {rights: [...rights]}})
			setData({...data, rights: [...rights]})
			dataChanged('right')
			toast(t('Saved'))

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')

		}
		finally
		{

			setSaving(false)

		}

	}

	const box = (list: string[], label: string) => {

		const count = list.filter((k) => rights.has(k)).length
		return <Checkbox size="small" disabled={!data.can_edit || !list.length} checked={list.length > 0 && count === list.length} indeterminate={count > 0 && count < list.length} onChange={(e) => set(list, e.target.checked)} slotProps={{input: {'aria-label': label}}} sx={{p: 0.5}} />

	}

	return (
		<>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25}}>
				<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 2, bgcolor: '#f0ecff', color: 'primary.main'}}><MenuIcon name={icon} /></Box>
				<Typography component="h1" noWrap sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em'}}>
					<ButtonBase onClick={() => navigate({search: ''})} sx={{font: 'inherit', letterSpacing: 'inherit', color: 'inherit', '&:hover': {color: 'primary.main'}}}>{data.title}</ButtonBase>
				</Typography>
			</Box>

			<Box sx={{display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1.5, px: 2, py: 1.25, bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '6px'}}>
				<Box component="label" htmlFor="rights-group" sx={{fontSize: 14, fontWeight: 500, color: '#3a3f55'}}>{t('Group')}</Box>
				<Select id="rights-group" value={data.groups.length ? data.group : ''} onChange={(e) => navigate({search: `?group=${e.target.value}`}, {replace: true})} sx={{width: {xs: '100%', sm: 320}, height: 40, fontSize: 14}}>
					{data.groups.map((g) => <MenuItem key={g.id} value={g.id}>{g.name} (#{g.id})</MenuItem>)}
				</Select>
				<Typography sx={{fontSize: 13, color: '#6b7088'}}>{t('Super admin has every right, it is not listed.')}</Typography>
				{data.can_edit && <Button variant="contained" startIcon={saving ? <CircularProgress size={16} color="inherit" /> : <SaveOutlined />} disabled={saving || !dirty || !data.groups.length} onClick={save} sx={{ml: 'auto'}}>{t('Save')}</Button>}
			</Box>

			{data.groups.length === 0 && <Typography sx={{fontSize: 14, color: '#6b7088'}}>{t('No group with a backend access.')}</Typography>}

			{data.groups.length > 0 && data.menus.map((menu) => {

				const all = menu.plugins.flatMap(keys)
				return (
					<Box component="section" key={menu.name} sx={{bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '6px', overflow: 'hidden'}}>
						<Box sx={{display: 'flex', alignItems: 'center', gap: 1, height: 36, px: 1.5, bgcolor: '#f5f6f8', borderBottom: `1px solid ${BORDER}`}}>
							{box(all, menu.name)}
							<MenuIcon name={menu.icon} fontSize={18} color={menu.color || undefined} />
							<Typography component="h2" sx={{fontSize: 12.5, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: '#3a3f55'}}>{menu.name}</Typography>
							<Typography sx={{ml: 'auto', fontSize: 12, color: '#6b7088'}}>{t('[COUNT] of [TOTAL] actions', {COUNT: all.filter((k) => rights.has(k)).length, TOTAL: all.length})}</Typography>
						</Box>
						{menu.plugins.map((plugin, i) => (
							<Box key={plugin.id} sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', md: '260px 1fr'}, alignItems: 'center', columnGap: 2, rowGap: 0.5, minHeight: 34, px: 1.5, py: 0.25, borderTop: i ? '1px solid #eef0f4' : 0, '&:hover': {bgcolor: '#f8f9fb'}}}>
								<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 13.5, minWidth: 0}}>
									{box(keys(plugin), plugin.name)}
									<MenuIcon name={plugin.icon} fontSize={18} color="#5b6178" />
									<Box component="span" sx={{whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{plugin.name}</Box>
								</Box>
								<Box sx={{display: 'flex', flexWrap: 'wrap', gap: 0.75, py: 0.25}}>
									{plugin.actions.map((action) => {

										const key = `${plugin.id}:${action}`
										const on = rights.has(key)
										return (
											<ButtonBase key={action} role="checkbox" aria-checked={on} aria-label={`${plugin.name}: ${action}`} disabled={!data.can_edit} onClick={() => set([key], !on)}
												sx={{height: 24, gap: 0.75, pl: 0.5, pr: 1.25, border: '1px solid', borderColor: on ? '#c9bdff' : '#e3e5ec', borderRadius: 1.5, bgcolor: on ? '#f0ecff' : '#fff', color: on ? '#4b2fd6' : '#3a3f55', fontSize: 12.5, fontWeight: on ? 500 : 400}}>
												<Checkbox size="small" checked={on} tabIndex={-1} sx={{p: 0, '& svg': {fontSize: 18}}} />{action}
											</ButtonBase>
										)

									})}
								</Box>
							</Box>
						))}
					</Box>
				)

			})}
		</>
	)

}
