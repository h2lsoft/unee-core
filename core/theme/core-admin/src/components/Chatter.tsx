import { useCallback, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { Autocomplete, Box, Button, ButtonBase, Checkbox, Chip, CircularProgress, ClickAwayListener, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, IconButton, InputBase, MenuItem, OutlinedInput, Select, TextField, Tooltip } from '@mui/material'
import { AttachFileOutlined, CheckOutlined, CloseOutlined, DeleteOutlineOutlined, PersonAddAltOutlined, SendOutlined, SentimentSatisfiedAltOutlined } from '@mui/icons-material'
import { api, ApiException, DATA_CHANGED_EVENT } from '../api'
import { getLanguage, t } from '../i18n'
import UserAvatar, { type AvatarData } from './UserAvatar'
import { useToast } from './Toast'
import { fileSize } from './FileInput'
import EmojiPicker from './EmojiPicker'

export type ChatUser = {id: number, name: string, avatar: AvatarData}
type Item = {kind: 'message' | 'note' | 'activity' | 'event', id: number, user_id?: number, date: string, user: string, avatar?: AvatarData | null, mine?: boolean, body?: string, email?: boolean, text?: string, attachments?: {id: number, name: string, size: number}[]}
export type ChatActivity = {id: number, activity: string, activity_label: string, title: string, note: string, due_date: string, state: 'late' | 'today' | 'planned', user: ChatUser}
export type ChatPayload = {thread: Item[], reads: {id: number, name: string, read_at: string}[], unread: number, followers: ChatUser[], following: boolean, activities: ChatActivity[], activity_types: Record<string, string>, users: ChatUser[], me: number, can_write: boolean, can_delete: boolean}
type Upload = {token: string, name: string, size: number}

// chatter of a record loaded by useChatter, shared by its 3 views
export type Chat = {base: string, data: ChatPayload, refresh: () => void, fail: (e: unknown) => void}

// colors of the activities by due date: late, today, planned
export const ACTIVITY_STATES = {
	late: {bg: 'var(--u-red-bg)', fg: 'var(--u-red)'},
	today: {bg: 'var(--u-orange-bg)', fg: 'var(--u-orange)'},
	planned: {bg: 'var(--u-green-bg)', fg: 'var(--u-green)'},
}

const escape = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

// text typed to html: paragraphs, line breaks, mentions in bold
function toHtml(text: string, names: string[]): string
{

	let html = escape(text.trim())
	for(const name of names)html = html.split(escape(`@${name}`)).join(`<strong>@${escape(name)}</strong>`)
	return html.split(/\n{2,}/).map((p) => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('')

}

const day = (date: string) => date ? new Date(`${date}T00:00:00`).toLocaleDateString(getLanguage(), {day: 'numeric', month: 'short'}) : ''

const outlined = {borderColor: 'var(--u-border-strong)', color: 'var(--u-text2)', fontWeight: 500}

/**
 * chatter of a record (plugin with the option chatter): GET /api/<module>/<id>/chatter/, loaded again after a write in its module
 * (form saved, transition fired); error when the record has no chatter or the user cannot read it
 */
export function useChatter(module: string, id: number): {chat: Chat | null, error: boolean}
{

	const toast = useToast()
	const [loaded, setLoaded] = useState<{base: string, data: ChatPayload} | null>(null)
	const [error, setError] = useState('')
	const [reload, setReload] = useState(0)
	const base = (module && id > 0) ? `${module}/${id}/chatter/` : ''
	const refresh = useCallback(() => setReload((n) => n + 1), [])

	useEffect(() => {

		if(!base)return
		let cancelled = false
		api<ChatPayload>(base).then((data) => { if(!cancelled){ setLoaded({base, data}); setError('') } }).catch(() => { if(!cancelled)setError(base) })
		return () => { cancelled = true }

	}, [base, reload])

	useEffect(() => {

		const changed = (e: Event) => { if((e as CustomEvent<string>).detail === module)refresh() }
		window.addEventListener(DATA_CHANGED_EVENT, changed)
		return () => window.removeEventListener(DATA_CHANGED_EVENT, changed)

	}, [module, refresh])

	const fail = useCallback((e: unknown) => toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error'), [toast])
	const data = (loaded && loaded.base === base) ? loaded.data : null
	return {chat: (base && data) ? {base, data, refresh, fail} : null, error: error !== '' && error === base}

}

// colors of the names of the others in the thread, by user
const NAME_COLORS = ['#b4235a', '#1d6fb8', '#2b8a3e', '#c2410c', '#7048e8', '#0b7285', '#a61e4d', '#5c7cfa']
const IMAGE = /\.(jpe?g|png|gif|webp)$/i
const FILE_COLORS: Record<string, string> = {pdf: '#e03131', doc: '#1c7ed6', docx: '#1c7ed6', xls: '#2b8a3e', xlsx: '#2b8a3e', csv: '#2b8a3e', zip: '#868e96'}

const time = (iso: string) => new Date(iso).toLocaleTimeString(getLanguage(), {hour: '2-digit', minute: '2-digit'})
const dayKey = (iso: string) => new Date(iso).toDateString()

// label of a day separator: today, yesterday, else the date
function dayLabel(iso: string): string
{

	const d = new Date(iso)
	const today = new Date()
	const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)
	if(d.toDateString() === today.toDateString())return t('Today')
	if(d.toDateString() === yesterday.toDateString())return t('Yesterday')
	return d.toLocaleDateString(getLanguage(), {weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric'})

}

const Lock = ({size = 13}: {size?: number}) => <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
const Envelope = () => <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" role="img" aria-label={t('also sent by email')}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" /></svg>
const Checks = ({double, read, label}: {double: boolean, read: boolean, label: string}) => (
	<Tooltip title={label}>
		<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={read ? 'var(--u-chat-read)' : 'currentColor'} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" role="img" aria-label={label}>
			{double ? <><path d="M2 13l4 4 8-9" /><path d="M10 15l2 2 8-9" /></> : <path d="M5 13l4 4 10-11" />}
		</svg>
	</Tooltip>
)

/**
 * thread in messaging style: oldest first, day separators, my messages on the right, the others on the left with their name,
 * internal notes in yellow, changes and activities done as centered lines, checks read by the followers; composer at the bottom
 * (message or internal note, also by email, emojis, attachments, mentions @, Enter sends, Shift+Enter new line)
 */
export function ChatterThread({chat}: {chat: Chat})
{

	const {base, data, refresh, fail} = chat
	const [mode, setMode] = useState<'message' | 'note'>('message')
	const [text, setText] = useState('')
	const [mentions, setMentions] = useState<ChatUser[]>([])
	const [mention_query, setMentionQuery] = useState<string | null>(null)
	const [email, setEmail] = useState(false)
	const [uploads, setUploads] = useState<Upload[]>([])
	const [uploading, setUploading] = useState(false)
	const [sending, setSending] = useState(false)
	const [emojis, setEmojis] = useState(false)
	const [to_remove, setToRemove] = useState<Item | null>(null)
	const input = useRef<HTMLTextAreaElement | null>(null)
	const file = useRef<HTMLInputElement | null>(null)
	const scroller = useRef<HTMLDivElement | null>(null)
	const last = data.thread.length ? `${data.thread.length}:${data.thread[data.thread.length - 1].date}` : ''

	// opened or a new line: shown at the bottom, read by the user (unread count of the button)
	useLayoutEffect(() => {

		if(scroller.current)scroller.current.scrollTop = scroller.current.scrollHeight

	}, [last])

	// unread count read when the effect runs, not a reason to run it again
	const unread = useRef(data.unread)
	unread.current = data.unread
	useEffect(() => {

		api(`${base}read/`, {method: 'POST'}).then(() => { if(unread.current > 0)refresh() }).catch(() => {})

	}, [base, last, refresh])

	// @ typed before the caret: list of the users to mention
	const typed = (value: string, caret: number) => {

		setText(value)
		const m = value.slice(0, caret).match(/(^|\s)@([\p{L}\p{N}._-]{0,30})$/u)
		setMentionQuery(m ? m[2].toLowerCase() : null)

	}

	const insert = (value: string) => {

		const el = input.current
		const start = el?.selectionStart ?? text.length
		const end = el?.selectionEnd ?? text.length
		setText(text.slice(0, start) + value + text.slice(end))
		window.setTimeout(() => { el?.focus(); el?.setSelectionRange(start + value.length, start + value.length) }, 0)

	}

	const mention = (user: ChatUser) => {

		const el = input.current
		const caret = el?.selectionStart ?? text.length
		const before = text.slice(0, caret).replace(/@([\p{L}\p{N}._-]{0,30})$/u, `@${user.name} `)
		setText(before + text.slice(caret))
		setMentions((list) => list.some((u) => u.id === user.id) ? list : [...list, user])
		setMentionQuery(null)
		window.setTimeout(() => { el?.focus(); el?.setSelectionRange(before.length, before.length) }, 0)

	}

	const attach = async (files: FileList | null) => {

		if(!files?.length)return
		setUploading(true)

		for(const f of Array.from(files).slice(0, 10))
		{

			const body = new FormData()
			body.append('file', f)

			try
			{

				const up = await api<Upload>(`${base}upload/`, {method: 'POST', body})
				setUploads((list) => [...list, up])

			}
			catch(e)
			{

				fail(e)

			}

		}

		setUploading(false)
		if(file.current)file.current.value = ''

	}

	const can_send = (text.trim() !== '' || uploads.length > 0) && !sending && !uploading

	const send = async () => {

		if(!can_send)return
		setSending(true)

		try
		{

			const used = mentions.filter((u) => text.includes(`@${u.name}`))
			await api(base, {method: 'POST', body: {type: mode, body: toHtml(text, used.map((u) => u.name)), email: mode === 'message' && email, uploads: uploads.map((u) => u.token), mentions: used.map((u) => u.id)}})
			setText('')
			setMentions([])
			setUploads([])
			setEmail(false)
			setEmojis(false)
			refresh()
			window.setTimeout(() => input.current?.focus(), 0)

		}
		catch(e)
		{

			fail(e)

		}
		finally
		{

			setSending(false)

		}

	}

	const remove = (item: Item) => api(`${base}${item.id}/`, {method: 'DELETE'}).then(() => { setToRemove(null); refresh() }).catch(fail)

	// read by: the followers but the author who opened the thread after the message
	const readers = (item: Item) => data.reads.filter((r) => r.id !== item.user_id && r.read_at >= item.date)
	const status = (item: Item) => {

		const by = readers(item)
		const targets = data.followers.filter((f) => f.id !== item.user_id)
		const all = targets.length > 0 && targets.every((f) => by.some((r) => r.id === f.id))
		const label = by.length ? t('Read by [NAMES]', {NAMES: by.map((r) => r.name).join(', ')}) : t('Sent')
		return <Checks double={by.length > 0} read={all} label={label} />

	}

	const matches = (mention_query === null) ? [] : data.users.filter((u) => u.id !== data.me && u.name.toLowerCase().includes(mention_query)).slice(0, 6)
	const note = mode === 'note'
	const chip = (on: boolean, color: string) => ({height: 26, px: 1.25, gap: 0.6, borderRadius: '13px', fontSize: 12, fontWeight: on ? 600 : 500, border: on ? 0 : '1px solid var(--u-border-strong)', bgcolor: on ? color : 'var(--u-paper)', color: on ? '#fff' : 'var(--u-text2)'})
	const pill = {alignSelf: 'center', maxWidth: '86%', px: 1.25, py: 0.5, borderRadius: '8px', fontSize: 11.5, textAlign: 'center', '& p': {m: 0, display: 'inline'}}
	const meta = {alignSelf: 'flex-end', display: 'flex', alignItems: 'center', gap: 0.5, fontSize: 11, color: 'var(--u-chat-time)', lineHeight: 1}

	const lines: ReactNode[] = []
	let previous: Item | null = null
	data.thread.forEach((item, i) => {

		if(!previous || dayKey(previous.date) !== dayKey(item.date))
		{

			lines.push(<Box key={`d${i}`} sx={{alignSelf: 'center', my: 0.5, px: 1.25, py: 0.5, borderRadius: '8px', bgcolor: 'var(--u-chat-other)', color: 'var(--u-text3)', fontSize: 11.5, fontWeight: 500, boxShadow: '0 1px 1px var(--u-shadow)', '&::first-letter': {textTransform: 'uppercase'}}}>{dayLabel(item.date)}</Box>)
			previous = null

		}

		if(item.kind === 'event')
		{

			lines.push(<Box key={`e${i}`} sx={{...pill, bgcolor: 'var(--u-chat-info)', color: 'var(--u-chat-info-text)'}}>{item.user} : {item.text} · {time(item.date)}</Box>)

		}
		else if(item.kind === 'activity')
		{

			lines.push(
				<Box key={item.id} sx={{...pill, bgcolor: 'var(--u-chat-done)', color: 'var(--u-chat-done-text)'}}>
					✓ {item.user} · <Box component="span" dangerouslySetInnerHTML={{__html: item.body ?? ''}} /> · {time(item.date)}
				</Box>
			)

		}
		else
		{

			const is_note = item.kind === 'note'
			const mine = !!item.mine
			// a run of the same author: name and corner on the first bubble only
			const first = !previous || previous.kind !== item.kind || previous.user_id !== item.user_id
			const color = NAME_COLORS[(item.user_id ?? 0) % NAME_COLORS.length]

			lines.push(
				<Box key={item.id} sx={{position: 'relative', alignSelf: mine ? 'flex-end' : 'flex-start', maxWidth: 'min(82%, 560px)', mt: first ? 0.75 : 0, display: 'flex', flexDirection: 'column', gap: 0.4, px: 1.1, pt: 0.75, pb: 0.5, minWidth: 90,
					borderRadius: first ? (mine ? '8px 0 8px 8px' : '0 8px 8px 8px') : '8px', bgcolor: is_note ? 'var(--u-chat-note)' : (mine ? 'var(--u-chat-mine)' : 'var(--u-chat-other)'),
					border: is_note ? '1px dashed var(--u-chat-note-border)' : 0, boxShadow: '0 1px 1px var(--u-shadow)', '&:hover .chat-delete': {opacity: 1}}}>
					{(is_note || (first && !mine)) && (
						<Box sx={{display: 'flex', alignItems: 'center', gap: 0.6, fontSize: 12.5, fontWeight: 600, color: is_note ? 'var(--u-chat-note-text)' : color, pr: data.can_delete ? 2.5 : 0}}>
							{is_note && <Lock />}{mine ? t('Internal note') : (is_note ? `${item.user} · ${t('internal note')}` : item.user)}
						</Box>
					)}
					{data.can_delete && (
						<IconButton className="chat-delete" size="small" aria-label={t('Delete')} onClick={() => setToRemove(item)} sx={{position: 'absolute', top: 2, right: 2, p: 0.25, opacity: 0, color: 'var(--u-chat-time)', '&:focus-visible': {opacity: 1}}}><DeleteOutlineOutlined sx={{fontSize: 15}} /></IconButton>
					)}
					{item.attachments?.map((f) => {

						const url = `/api/${base}attachments/${f.id}/`
						if(IMAGE.test(f.name))
							return <Box key={f.id} component="a" href={url} target="_blank" rel="noopener noreferrer" sx={{display: 'block', mx: -0.6, mt: 0.25}}><Box component="img" src={url} alt={f.name} loading="lazy" sx={{display: 'block', maxWidth: 320, maxHeight: 240, width: '100%', objectFit: 'cover', borderRadius: '6px'}} /></Box>

						const ext = (f.name.split('.').pop() ?? '').toLowerCase()
						return (
							<Box key={f.id} component="a" href={url} target="_blank" rel="noopener noreferrer" sx={{display: 'flex', alignItems: 'center', gap: 1.25, px: 1.25, py: 1, borderRadius: '6px', bgcolor: 'var(--u-hover)', color: 'var(--u-text)', textDecoration: 'none', '&:hover': {color: 'primary.main'}}}>
								<Box component="span" sx={{width: 32, height: 38, flexShrink: 0, borderRadius: '4px', bgcolor: FILE_COLORS[ext] ?? '#868e96', color: '#fff', fontSize: 9.5, fontWeight: 600, display: 'flex', alignItems: 'center', justifyContent: 'center', textTransform: 'uppercase'}}>{ext.slice(0, 4) || '?'}</Box>
								<Box component="span" sx={{display: 'flex', flexDirection: 'column', gap: 0.25, minWidth: 0}}>
									<Box component="span" sx={{fontSize: 13.5, fontWeight: 500, overflowWrap: 'anywhere'}}>{f.name}</Box>
									<Box component="span" sx={{fontSize: 11.5, color: 'var(--u-text3)'}}>{fileSize(f.size)}</Box>
								</Box>
							</Box>
						)

					})}
					{/* html of the server, cleaned by \Core\Sanitizer (profile basic) */}
					{item.body && <Box sx={{fontSize: 14, lineHeight: 1.4, overflowWrap: 'anywhere', '& p': {m: 0}, '& p + p': {mt: 0.75}, '& strong': {fontWeight: 600, color: 'primary.main'}}} dangerouslySetInnerHTML={{__html: item.body}} />}
					<Box sx={meta}>
						{item.email && <Envelope />}
						{time(item.date)}
						{mine && status(item)}
					</Box>
				</Box>
			)

		}

		previous = item

	})

	return (
		<Box sx={{position: 'relative', display: 'flex', flexDirection: 'column', height: '100%', minHeight: 0}}>
			<Box ref={scroller} sx={{flex: '1 1 auto', minHeight: 0, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 0.5, px: 1.75, py: 1.5, bgcolor: 'var(--u-chat-bg)', backgroundImage: 'radial-gradient(var(--u-chat-dots) 1px, transparent 1px)', backgroundSize: '16px 16px'}}>
				{data.thread.length === 0 && <Box sx={{...pill, mt: 2, bgcolor: 'var(--u-chat-info)', color: 'var(--u-chat-info-text)'}}>{t('No message yet')}</Box>}
				{lines}
			</Box>

			{data.can_write ? (
				<Box sx={{position: 'relative', display: 'flex', flexDirection: 'column', gap: 1, px: 1.25, pt: 1, pb: 1.25, bgcolor: note ? 'var(--u-yellow-bg)' : 'var(--u-chat-bar)', borderTop: '1px solid var(--u-border)'}}>
					{emojis && (
						<ClickAwayListener onClickAway={() => setEmojis(false)}>
							<Box sx={{position: 'absolute', zIndex: 3, left: 10, width: 'min(380px, calc(100% - 20px))', bottom: 'calc(100% + 6px)'}}>
								<EmojiPicker onPick={insert} onClose={() => { setEmojis(false); input.current?.focus() }} />
							</Box>
						</ClickAwayListener>
					)}
					{matches.length > 0 && (
						<Box role="listbox" aria-label={t('Mention')} sx={{position: 'absolute', zIndex: 3, left: 90, width: 'min(300px, calc(100% - 146px))', bottom: 'calc(100% - 44px)', py: 0.75, bgcolor: 'var(--u-paper)', borderRadius: '10px', boxShadow: '0 8px 28px var(--u-shadow), 0 0 0 1px var(--u-border)'}}>
							{matches.map((u, i) => (
								<Box key={u.id} role="option" aria-selected={i === 0} onMouseDown={(e) => { e.preventDefault(); mention(u) }}
									sx={{display: 'flex', alignItems: 'center', gap: 1.25, px: 1.5, py: 0.75, fontSize: 14, cursor: 'pointer', bgcolor: i === 0 ? 'var(--u-hover)' : 'transparent', '&:hover': {bgcolor: 'var(--u-hover)'}}}><UserAvatar avatar={u.avatar} size={28} />{u.name}</Box>
							))}
						</Box>
					)}
					<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75, flexWrap: 'wrap'}}>
						<ButtonBase aria-pressed={!note} onClick={() => setMode('message')} sx={chip(!note, 'var(--mui-palette-primary-main)')}>{t('Message')}</ButtonBase>
						<ButtonBase aria-pressed={note} onClick={() => setMode('note')} sx={{...chip(note, '#e8b10c'), color: note ? '#3a2c00' : 'var(--u-text2)'}}><Lock size={12} />{t('Internal note')}</ButtonBase>
						<Box sx={{flexGrow: 1}} />
						{note
							? <Box sx={{fontSize: 11.5, color: 'var(--u-chat-note-text)'}}>{t('never sent by email')}</Box>
							: <Tooltip title={t('uses email credits')}><ButtonBase aria-pressed={email} onClick={() => setEmail(!email)} sx={chip(email, 'var(--mui-palette-primary-main)')}><Envelope />{t('Also by email')}</ButtonBase></Tooltip>}
					</Box>
					{uploads.length > 0 && (
						<Box sx={{display: 'flex', gap: 0.75, flexWrap: 'wrap'}}>
							{uploads.map((u) => <Chip key={u.token} size="small" icon={<AttachFileOutlined />} label={`${u.name} - ${fileSize(u.size)}`} onDelete={() => setUploads((list) => list.filter((x) => x.token !== u.token))} />)}
						</Box>
					)}
					<Box sx={{display: 'flex', alignItems: 'flex-end', gap: 0.5}}>
						<IconButton aria-label={t('Emojis')} aria-expanded={emojis} onClick={() => setEmojis(!emojis)} sx={{color: emojis ? 'primary.main' : 'var(--u-text3)', bgcolor: emojis ? 'var(--u-accent-bg)' : 'transparent'}}><SentimentSatisfiedAltOutlined /></IconButton>
						<input ref={file} type="file" multiple hidden onChange={(e) => attach(e.target.files)} />
						<IconButton aria-label={t('Attach')} disabled={uploading} onClick={() => file.current?.click()} sx={{color: 'var(--u-text3)'}}>{uploading ? <CircularProgress size={20} color="inherit" /> : <AttachFileOutlined />}</IconButton>
						<InputBase
							inputRef={input}
							multiline
							maxRows={6}
							fullWidth
							value={text}
							placeholder={note ? t('Write an internal note') : t('Write a message')}
							onChange={(e) => typed(e.target.value, e.target.selectionStart ?? e.target.value.length)}
							onKeyDown={(e) => {

								if(e.key === 'Escape' && emojis){ e.stopPropagation(); setEmojis(false); return }

								if(mention_query !== null && matches.length)
								{

									if(e.key === 'Escape'){ e.stopPropagation(); setMentionQuery(null); return }
									if(e.key === 'Enter' || e.key === 'Tab'){ e.preventDefault(); mention(matches[0]); return }

								}

								// Enter sends, Shift+Enter goes to a new line (not while composing an accent)
								if(e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing){ e.preventDefault(); send() }

							}}
							inputProps={{'aria-label': note ? t('Internal note') : t('Message')}}
							sx={{flex: '1 1 auto', minWidth: 0, px: 1.75, py: 1, borderRadius: '20px', bgcolor: 'var(--u-paper)', fontSize: 14, border: note ? '1px solid var(--u-chat-note-border)' : '1px solid transparent'}}
						/>
						<IconButton aria-label={note ? t('Log note') : t('Send')} disabled={!can_send} onClick={send}
							sx={{width: 42, height: 42, flexShrink: 0, bgcolor: note ? '#e8b10c' : 'primary.main', color: note ? '#3a2c00' : 'primary.contrastText', '&:hover': {bgcolor: note ? '#d19e00' : 'primary.dark'}, '&.Mui-disabled': {bgcolor: 'var(--u-border)', color: 'var(--u-text3)'}}}>
							{sending ? <CircularProgress size={18} color="inherit" /> : <SendOutlined sx={{fontSize: 20}} />}
						</IconButton>
					</Box>
				</Box>
			) : (
				<Box sx={{px: 2, py: 1.5, fontSize: 12.5, color: 'var(--u-text3)', textAlign: 'center', bgcolor: 'var(--u-chat-bar)', borderTop: '1px solid var(--u-border)'}}>{t('Read only: you cannot write in this thread')}</Box>
			)}

			<Dialog open={!!to_remove} onClose={(_, reason) => reason !== 'backdropClick' && setToRemove(null)} maxWidth="xs" fullWidth>
				<DialogTitle sx={{display: 'flex', alignItems: 'center', pr: 1.5, fontSize: 18, fontWeight: 600}}>
					<Box component="span" sx={{flexGrow: 1}}>{t('Delete')}</Box>
					<IconButton aria-label={t('Close')} onClick={() => setToRemove(null)}><CloseOutlined /></IconButton>
				</DialogTitle>
				<DialogContent><Box sx={{fontSize: 14}}>{t('Delete this message? The deletion is logged.')}</Box></DialogContent>
				<DialogActions sx={{px: 3, pb: 2.5}}>
					<Button variant="contained" color="error" onClick={() => to_remove && remove(to_remove)}>{t('Delete')}</Button>
				</DialogActions>
			</Dialog>
		</Box>
	)

}

/**
 * activities: form to schedule one (type, summary, due date, assigned user, email reminder, note), then the planned ones,
 * colored by due date, each one marked done with an optional comment
 */
export function ChatterActivities({chat}: {chat: Chat})
{

	const {base, data, refresh, fail} = chat
	const empty = {activity: 'todo', title: '', due_date: '', responsible_id: 0, note: '', email: false}
	const [activity, setActivity] = useState(empty)
	const [sending, setSending] = useState(false)
	const [done, setDone] = useState<ChatActivity | null>(null)
	const [comment, setComment] = useState('')

	const schedule = async () => {

		setSending(true)

		try
		{

			await api(`${base}activities/`, {method: 'POST', body: {...activity, responsible_id: activity.responsible_id || data.me}})
			setActivity(empty)
			refresh()

		}
		catch(e)
		{

			fail(e)

		}
		finally
		{

			setSending(false)

		}

	}

	const markDone = async () => {

		if(!done)return

		try
		{

			await api(`${base}activities/${done.id}/done/`, {method: 'POST', body: {comment}})
			setDone(null)
			setComment('')
			refresh()

		}
		catch(e)
		{

			fail(e)

		}

	}

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.5}}>
			{data.can_write && (
				<Box sx={{display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)', gap: 1, p: 1.5, border: '1px solid var(--u-border)', borderRadius: '8px'}}>
					<Box sx={{gridColumn: '1 / -1', fontSize: 13, fontWeight: 600}}>{t('Schedule activity')}</Box>
					<Select size="small" value={activity.activity} onChange={(e) => setActivity({...activity, activity: e.target.value})} inputProps={{'aria-label': t('Activity')}} sx={{fontSize: 14}}>
						{Object.entries(data.activity_types).map(([key, label]) => <MenuItem key={key} value={key}>{label}</MenuItem>)}
					</Select>
					<OutlinedInput size="small" type="date" value={activity.due_date} onChange={(e) => setActivity({...activity, due_date: e.target.value})} inputProps={{'aria-label': t('Due date')}} />
					<OutlinedInput size="small" value={activity.title} placeholder={t('Summary')} onChange={(e) => setActivity({...activity, title: e.target.value})} inputProps={{maxLength: 255, 'aria-label': t('Summary')}} sx={{gridColumn: '1 / -1'}} />
					<Autocomplete
						size="small"
						options={data.users}
						value={data.users.find((u) => u.id === (activity.responsible_id || data.me)) ?? null}
						getOptionLabel={(u) => u.name}
						isOptionEqualToValue={(a, b) => a.id === b.id}
						onChange={(_, u) => setActivity({...activity, responsible_id: u?.id ?? 0})}
						renderOption={({key, ...props}, u) => <Box component="li" key={key} {...props} sx={{gap: 1, fontSize: 14}}><UserAvatar avatar={u.avatar} size={22} />{u.name}</Box>}
						renderInput={(params) => <TextField {...params} placeholder={t('Assigned to')} slotProps={{...params.slotProps, htmlInput: {...params.slotProps.htmlInput, 'aria-label': t('Assigned to')}}} />}
						sx={{gridColumn: '1 / -1'}}
					/>
					<OutlinedInput size="small" multiline minRows={2} value={activity.note} placeholder={t('Note')} onChange={(e) => setActivity({...activity, note: e.target.value})} inputProps={{'aria-label': t('Note')}} sx={{gridColumn: '1 / -1', fontSize: 14}} />
					<Box sx={{gridColumn: '1 / -1', display: 'flex', alignItems: 'center', gap: 1}}>
						<FormControlLabel control={<Checkbox size="small" checked={activity.email} onChange={(e) => setActivity({...activity, email: e.target.checked})} />} label={t('Email reminder')} slotProps={{typography: {sx: {fontSize: 13}}}} />
						<Box sx={{flexGrow: 1}} />
						<Button variant="contained" size="small" disabled={activity.title.trim() === '' || sending} onClick={schedule} sx={{height: 30}}>{sending ? <CircularProgress size={16} color="inherit" /> : t('Schedule')}</Button>
					</Box>
				</Box>
			)}

			<Box sx={{fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--u-text3)'}}>{t('Planned activities')}</Box>
			{data.activities.length === 0 && <Box sx={{fontSize: 13, color: 'var(--u-text3)'}}>{t('No planned activity')}</Box>}
			{data.activities.map((a) => (
				<Box key={a.id} sx={{display: 'flex', alignItems: 'center', gap: 1.25, flexWrap: 'wrap', px: 1.25, py: 0.75, borderRadius: '6px', bgcolor: ACTIVITY_STATES[a.state].bg}}>
					<Box component="span" sx={{px: 0.75, borderRadius: '4px', bgcolor: 'var(--u-paper)', color: ACTIVITY_STATES[a.state].fg, fontSize: 12, fontWeight: 600}}>
						{a.state === 'late' ? t('Late - [DATE]', {DATE: day(a.due_date)}) : (a.state === 'today' ? t('Today') : (a.due_date ? day(a.due_date) : t('No date')))}
					</Box>
					<Box component="span" sx={{fontSize: 13, fontWeight: 600}}>{a.activity_label}</Box>
					<Box component="span" title={a.note} sx={{fontSize: 13, color: 'var(--u-text2)', flex: '1 1 120px', minWidth: 0}}>{a.title}</Box>
					<UserAvatar avatar={a.user.avatar} title={a.user.name} size={22} />
					{data.can_write && <Button size="small" variant="outlined" color="inherit" onClick={() => setDone(a)} sx={{height: 26, bgcolor: 'var(--u-paper)', ...outlined}}>{t('Done')}</Button>}
				</Box>
			))}

			<Dialog open={!!done} onClose={(_, reason) => reason !== 'backdropClick' && setDone(null)} maxWidth="xs" fullWidth>
				<DialogTitle sx={{display: 'flex', alignItems: 'center', pr: 1.5, fontSize: 18, fontWeight: 600}}>
					<Box component="span" sx={{flexGrow: 1}}>{t('Activity done')}</Box>
					<IconButton aria-label={t('Close')} onClick={() => setDone(null)}><CloseOutlined /></IconButton>
				</DialogTitle>
				<DialogContent>
					<Box sx={{fontSize: 14, mb: 1.5}}>{done?.activity_label}: {done?.title}</Box>
					<OutlinedInput multiline minRows={3} fullWidth value={comment} placeholder={t('Comment (optional)')} onChange={(e) => setComment(e.target.value)} inputProps={{maxLength: 2000, 'aria-label': t('Comment')}} sx={{fontSize: 14}} />
				</DialogContent>
				<DialogActions sx={{px: 3, pb: 2.5}}>
					<Button variant="contained" onClick={markDone}>{t('Done')}</Button>
				</DialogActions>
			</Dialog>
		</Box>
	)

}

/**
 * followers: follow or stop, the followers (removed by a writer), add one; followers get the messages and the notifications
 */
export function ChatterFollowers({chat}: {chat: Chat})
{

	const {base, data, refresh, fail} = chat
	const follow = (on: boolean) => api(`${base}follow/`, {method: 'POST', body: {follow: on}}).then(refresh).catch(fail)
	const add = (user: ChatUser) => api(`${base}followers/`, {method: 'POST', body: {xcore_user_id: user.id}}).then(refresh).catch(fail)
	const remove = (user: ChatUser) => api(`${base}followers/${user.id}/`, {method: 'DELETE'}).then(refresh).catch(fail)

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.5}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
				<Box sx={{flexGrow: 1, fontSize: 13, color: 'var(--u-text2)'}}>{t('[COUNT] followers', {COUNT: data.followers.length})}</Box>
				<Button size="small" variant={data.following ? 'outlined' : 'contained'} color={data.following ? 'inherit' : 'primary'} onClick={() => follow(!data.following)} startIcon={data.following ? <CheckOutlined fontSize="small" /> : undefined} sx={{height: 30, ...(data.following ? outlined : {})}}>{data.following ? t('Following') : t('Follow')}</Button>
			</Box>
			{data.followers.length === 0 && <Box sx={{fontSize: 13, color: 'var(--u-text3)'}}>{t('No follower')}</Box>}
			{data.followers.map((u) => (
				<Box key={u.id} sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 14}}>
					<UserAvatar avatar={u.avatar} size={28} />
					<Box sx={{flexGrow: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{u.name}</Box>
					{data.can_write && <IconButton size="small" aria-label={`${t('Remove')} ${u.name}`} onClick={() => remove(u)}><CloseOutlined sx={{fontSize: 16}} /></IconButton>}
				</Box>
			))}
			{data.can_write && (
				<Autocomplete
					size="small"
					options={data.users.filter((u) => !data.followers.some((f) => f.id === u.id))}
					value={null}
					getOptionLabel={(u) => u.name}
					onChange={(_, u) => u && add(u)}
					renderOption={({key, ...props}, u) => <Box component="li" key={key} {...props} sx={{gap: 1, fontSize: 14}}><UserAvatar avatar={u.avatar} size={22} />{u.name}</Box>}
					renderInput={(params) => <TextField {...params} placeholder={t('Add a follower')} slotProps={{...params.slotProps, htmlInput: {...params.slotProps.htmlInput, 'aria-label': t('Add a follower')}, input: {...params.slotProps.input, startAdornment: <PersonAddAltOutlined sx={{fontSize: 18, color: 'var(--u-text3)', ml: 0.5}} />}}} />}
				/>
			)}
		</Box>
	)

}
