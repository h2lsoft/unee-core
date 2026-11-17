import { useCallback, useEffect, useRef, useState } from 'react'
import { Box, Button, Checkbox, FormControlLabel, IconButton, LinearProgress, Tooltip, Typography } from '@mui/material'
import { CheckOutlined, CloseOutlined, ErrorOutlineOutlined } from '@mui/icons-material'
import { ApiException } from '../../api'
import { t } from '../../i18n'
import { driveSize, errorMessage, sendFile, type Conflict, type Upload } from './driveApi'

let next_key = 1

// panel of the imports closed after this delay once all is finished (ms)
const CLOSE_DELAY = 15000

/**
 * queue of the uploads: one file at a time, a name taken waits for the choice of the user (replace, keep both, skip, applied to the next ones on demand)
 *
 * @param onDone a file is stored in a folder (list and quota to reload)
 */
export function useUploads(onDone: (folder: number) => void)
{

	const [queue, setQueue] = useState<Upload[]>([])
	const [choice, setChoice] = useState<Conflict | ''>('')
	const busy = useRef(false)
	const canceled = useRef(new Set<number>())
	const choices = useRef(new Map<number, Conflict>())
	const done = useRef(onDone)
	useEffect(() => { done.current = onDone }, [onDone])

	const patch = useCallback((key: number, changes: Partial<Upload>) => setQueue((q) => q.map((u) => (u.key === key) ? {...u, ...changes} : u)), [])

	const add = useCallback((files: File[], folder: number) => {

		if(!files.length)return
		setQueue((q) => [...q, ...files.map((file): Upload => ({key: next_key++, file, folder, name: file.name, status: 'waiting', received: 0, error: '', duplicate: '', upload: ''}))])

	}, [])

	// next waiting file
	useEffect(() => {

		if(busy.current)return
		const item = queue.find((u) => u.status === 'waiting')
		if(!item)return

		busy.current = true
		const conflict = choices.current.get(item.key) ?? choice
		patch(item.key, {status: 'uploading', error: ''})

		sendFile(item, conflict, (changes) => patch(item.key, changes), () => canceled.current.has(item.key))
			.then((result) => {

				patch(item.key, (result.skipped) ? {status: 'skipped'} : {status: 'done', received: item.file.size, duplicate: result.duplicate ?? ''})
				if(!result.skipped)done.current(item.folder)

			})
			.catch((e) => {

				if(canceled.current.has(item.key))patch(item.key, {status: 'canceled'})
				else if(e instanceof ApiException && e.error.code === 409 && (e.error as unknown as Record<string, unknown>).conflict)patch(item.key, {status: 'conflict'})
				else patch(item.key, {status: 'error', error: errorMessage(e)})

			})
			.finally(() => {

				busy.current = false
				setQueue((q) => [...q])

			})

	}, [queue, choice, patch])

	// choice for one file, or for it, the files waiting in conflict and the next ones
	const resolve = (key: number, conflict: Conflict, all: boolean) => {

		if(all)setChoice(conflict)
		setQueue((q) => q.map((u) => {

			if(u.key !== key && !(all && u.status === 'conflict'))return u
			choices.current.set(u.key, conflict)
			return {...u, status: 'waiting'}

		}))

	}

	const cancel = (key: number) => {

		canceled.current.add(key)
		setQueue((q) => q.map((u) => (u.key === key && (u.status === 'waiting' || u.status === 'conflict')) ? {...u, status: 'canceled'} : u))

	}

	const clear = useCallback(() => {

		setQueue((q) => q.filter((u) => ['waiting', 'uploading', 'conflict'].includes(u.status)))
		setChoice('')

	}, [])

	return {queue, add, resolve, cancel, clear}

}

/**
 * panel of the uploads, bottom right of the File Manager
 */
export function UploadPanel({queue, onResolve, onCancel, onClear}: {queue: Upload[], onResolve: (key: number, conflict: Conflict, all: boolean) => void, onCancel: (key: number) => void, onClear: () => void})
{

	const [all, setAll] = useState(false)
	const [hover, setHover] = useState(false)

	const finished = queue.filter((u) => ['done', 'skipped', 'error', 'canceled'].includes(u.status)).length
	const running = queue.length - finished
	const conflicts = queue.filter((u) => u.status === 'conflict').length
	const failed = queue.some((u) => u.status === 'error')

	// imports finished: the panel closes after 15s (bar under it), started again when the mouse leaves it, kept when a file failed (its message stays readable)
	const closing = queue.length > 0 && !running && !failed
	const [left, setLeft] = useState(CLOSE_DELAY)
	useEffect(() => {

		if(!closing || hover)return
		const end = Date.now() + CLOSE_DELAY
		const timer = setInterval(() => {

			const rest = Math.max(0, end - Date.now())
			setLeft(rest)
			if(!rest)
			{

				clearInterval(timer)
				onClear()

			}

		}, 100)

		return () => { clearInterval(timer); setLeft(CLOSE_DELAY) }

	}, [closing, hover, onClear])

	if(!queue.length)return null

	return (
		<Box role="region" aria-label={t('Imports')} onMouseEnter={() => setHover(true)} onMouseLeave={() => setHover(false)} sx={{position: 'absolute', right: 14, bottom: 14, width: 360, maxWidth: 'calc(100% - 28px)', maxHeight: '60%', display: 'flex', flexDirection: 'column', bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '10px', boxShadow: '0 8px 24px rgba(16, 20, 40, .12)', zIndex: 4, overflow: 'hidden'}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, pl: 1.75, pr: 1, py: 0.75, borderBottom: '1px solid var(--u-border-soft)'}}>
				<Typography sx={{flex: 1, fontSize: 13.5, fontWeight: 600}}>{running ? t('Import [N] of [TOTAL]', {N: finished + 1, TOTAL: queue.length}) : t('Imports finished')}</Typography>
				{!running && <IconButton size="small" aria-label={t('Close')} onClick={onClear}><CloseOutlined sx={{fontSize: 18}} /></IconButton>}
			</Box>
			<Box sx={{overflowY: 'auto', p: '8px 14px', display: 'flex', flexDirection: 'column', gap: 1.25, fontSize: 13}}>
				{queue.map((u) => (
					<Box key={u.key} sx={{display: 'flex', flexDirection: 'column', gap: 0.5}}>
						<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
							<Box component="span" title={u.name} sx={{flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: ['skipped', 'canceled'].includes(u.status) ? 'var(--u-muted)' : 'var(--u-text)'}}>{u.name}</Box>
							{u.status === 'uploading' && <Box component="span" sx={{fontSize: 12, color: 'var(--u-text3)', whiteSpace: 'nowrap'}}>{t('[DONE] of [TOTAL]', {DONE: driveSize(u.received), TOTAL: driveSize(u.file.size)})}</Box>}
							{u.status === 'waiting' && <Box component="span" sx={{fontSize: 12, color: 'var(--u-muted)'}}>{t('Waiting')}</Box>}
							{u.status === 'skipped' && <Box component="span" sx={{fontSize: 12, color: 'var(--u-muted)'}}>{t('Skipped')}</Box>}
							{u.status === 'canceled' && <Box component="span" sx={{fontSize: 12, color: 'var(--u-muted)'}}>{t('Canceled')}</Box>}
							{u.status === 'done' && <CheckOutlined role="img" aria-label={t('Done')} sx={{fontSize: 18, color: '#0f6a55'}} />}
							{u.status === 'error' && <Tooltip title={u.error}><ErrorOutlineOutlined role="img" aria-label={u.error} sx={{fontSize: 18, color: 'error.main'}} /></Tooltip>}
							{['waiting', 'uploading', 'conflict'].includes(u.status) && <IconButton size="small" aria-label={t('Cancel the import of [NAME]', {NAME: u.name})} onClick={() => onCancel(u.key)} sx={{p: 0.25}}><CloseOutlined sx={{fontSize: 16}} /></IconButton>}
						</Box>
						{u.status === 'uploading' && <LinearProgress variant="determinate" value={u.file.size ? (u.received * 100 / u.file.size) : 100} sx={{height: 4, borderRadius: 2}} />}
						{u.status === 'error' && <Box sx={{fontSize: 12, color: 'error.main'}}>{u.error}</Box>}
						{u.status === 'done' && u.duplicate && <Box sx={{fontSize: 12, color: '#9a5a1e'}}>{t('Same content as [NAME] in this folder', {NAME: u.duplicate})}</Box>}
						{u.status === 'conflict' && (
							<Box sx={{display: 'flex', flexDirection: 'column', gap: 1, p: 1.25, borderRadius: 2, bgcolor: '#fbf1e6', color: '#6e3f12'}}>
								<Box>{t('[NAME] already exists in this folder', {NAME: u.name})}</Box>
								<Box sx={{display: 'flex', gap: 0.75, flexWrap: 'wrap'}}>
									{(['replace', 'keep_both', 'skip'] as Conflict[]).map((c) => (
										<Button key={c} size="small" variant="outlined" onClick={() => onResolve(u.key, c, all)} sx={{height: 28, fontSize: 12.5, bgcolor: '#fff', color: '#1f2335', borderColor: 'var(--u-border-strong)'}}>
											{{replace: t('Replace'), keep_both: t('Keep both'), skip: t('Skip')}[c]}
										</Button>
									))}
								</Box>
								{(conflicts > 1 || queue.some((q) => q.status === 'waiting')) && <FormControlLabel control={<Checkbox size="small" checked={all} onChange={(e) => setAll(e.target.checked)} sx={{p: 0.5}} />} label={t('Apply to the next ones')} sx={{m: 0, '& .MuiFormControlLabel-label': {fontSize: 12.5}}} />}
							</Box>
						)}
					</Box>
				))}
			</Box>
			{closing && <LinearProgress variant="determinate" value={left * 100 / CLOSE_DELAY} aria-label={t('Closes in [N] s', {N: Math.ceil(left / 1000)})} title={t('Closes in [N] s', {N: Math.ceil(left / 1000)})}
				sx={{height: 3, flexShrink: 0, bgcolor: 'var(--u-border-soft)', '& .MuiLinearProgress-bar': {transition: 'transform .1s linear'}}} />}
		</Box>
	)

}
