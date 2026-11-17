import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Alert, Box, Button, ButtonBase, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, FormHelperText, IconButton, InputLabel, OutlinedInput, Typography } from '@mui/material'
import { ChevronRightOutlined, CloseOutlined, FolderRounded, HomeOutlined } from '@mui/icons-material'
import { ApiException } from '../../api'
import { t } from '../../i18n'
import { FIELDS_SX } from '../FormDialog'
import { DEFAULT_SORT, driveApi, errorMessage, type DriveFolder } from './driveApi'

/**
 * dialog of the drive, same look as the form dialog: title 18px, close cross, no cancel button (rule of the backend)
 */
export function DriveDialog({open, title, onClose, children, actions, width = 'sm', center = false}: {open: boolean, title: string, onClose: () => void, children: ReactNode, actions?: ReactNode, width?: 'xs' | 'sm' | 'md', center?: boolean})
{

	// the dialogs open at the top of the screen (theme), the confirmations in the middle
	return (
		<Dialog open={open} onClose={(_, reason) => reason !== 'backdropClick' && onClose()} maxWidth={width} fullWidth onKeyDown={(e) => e.stopPropagation()}
			sx={center ? {'& .MuiDialog-container': {alignItems: 'center'}} : undefined}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 18, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{title}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{pt: 1, fontSize: 14, ...FIELDS_SX}}>{children}</DialogContent>
			{actions && <DialogActions sx={{px: 3, pt: 1, pb: 2.5, gap: 1}}>{actions}</DialogActions>}
		</Dialog>
	)

}

/**
 * row of a field like the form dialog: label on the left (190px, right aligned), control, help or error under it
 */
export function DriveRow({id, label, help, error, children}: {id: string, label: string, help?: string, error?: string, children: ReactNode})
{

	return (
		<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '190px 1fr'}, columnGap: 2.5, rowGap: 0.5, alignItems: 'start', py: 0.5}}>
			<InputLabel htmlFor={id} sx={{fontSize: 14, fontWeight: 500, color: 'var(--u-text2)', whiteSpace: 'normal', textAlign: {xs: 'left', sm: 'right'}, lineHeight: {sm: '34px'}}}>{label}</InputLabel>
			<Box sx={{minWidth: 0}}>
				{children}
				{(error || help) && <FormHelperText error={!!error} sx={{m: 0, mt: 0.5}}>{error || help}</FormHelperText>}
			</Box>
		</Box>
	)

}

/**
 * name of a new folder or new name of an item: the base name is selected (extension of a file left out)
 */
export function NameDialog({open, title, value, file, onClose, onSubmit}: {open: boolean, title: string, value: string, file: boolean, onClose: () => void, onSubmit: (name: string) => Promise<void>})
{

	const [name, setName] = useState(value)
	const [error, setError] = useState('')
	const [busy, setBusy] = useState(false)
	const input = useRef<HTMLInputElement>(null)

	// base name selected once the dialog shows the value (extension of a file left out)
	useEffect(() => {

		if(!open)return
		setName(value)
		setError('')

		const timer = setTimeout(() => {

			const el = input.current
			if(!el)return
			const dot = file ? value.lastIndexOf('.') : -1
			el.focus()
			el.setSelectionRange(0, (dot > 0) ? dot : value.length)

		}, 50)

		return () => clearTimeout(timer)

	}, [open, value, file])

	const submit = async () => {

		if(!name.trim() || busy)return
		setBusy(true)
		setError('')
		try
		{

			await onSubmit(name.trim())

		}
		catch(e)
		{

			setError((e instanceof ApiException) ? (e.error.fields?.name?.[0] ?? e.error.message) : errorMessage(e))

		}
		finally
		{

			setBusy(false)

		}

	}

	return (
		<DriveDialog open={open} title={title} onClose={onClose}
			actions={<Button variant="contained" onClick={submit} disabled={busy || !name.trim()}>{busy ? <CircularProgress size={20} color="inherit" /> : t('Save')}</Button>}>
			<DriveRow id="drive-name" label={t('Name')} error={error}>
				<OutlinedInput id="drive-name" inputRef={input} autoFocus fullWidth value={name} onChange={(e) => setName(e.target.value)} error={!!error}
					onKeyDown={(e) => { if(e.key === 'Enter'){ e.preventDefault(); submit() } }} inputProps={{maxLength: 255}} sx={{fontSize: 14}} />
			</DriveRow>
		</DriveDialog>
	)

}

/**
 * choice of a target folder (move to, copy to): navigation in the folders, the folders moved and the ones without write right refused
 */
export function FolderPickerDialog({open, title, action, start, excluded, onClose, onSubmit}: {open: boolean, title: string, action: string, start: number, excluded: number[], onClose: () => void, onSubmit: (folder: number) => Promise<void>})
{

	const [current, setCurrent] = useState(start)
	const [content, setContent] = useState<{folders: DriveFolder[], breadcrumb: {id: number, name: string}[], write: boolean} | null>(null)
	const [error, setError] = useState('')
	const [busy, setBusy] = useState(false)

	useEffect(() => { if(open)setCurrent(start) }, [open, start])

	useEffect(() => {

		if(!open)return
		let alive = true
		setContent(null)
		setError('')
		driveApi.folder(current, DEFAULT_SORT, '')
			.then((c) => alive && setContent({folders: c.folders, breadcrumb: c.breadcrumb, write: c.rights.write}))
			.catch((e) => alive && setError(errorMessage(e)))

		return () => { alive = false }

	}, [open, current])

	const inside = content?.breadcrumb.some((b) => excluded.includes(b.id)) ?? false

	const submit = async () => {

		setBusy(true)
		setError('')
		try
		{

			await onSubmit(current)

		}
		catch(e)
		{

			setError(errorMessage(e))

		}
		finally
		{

			setBusy(false)

		}

	}

	return (
		<DriveDialog open={open} title={title} onClose={onClose}
			actions={<Button variant="contained" onClick={submit} disabled={busy || !content?.write || inside}>{busy ? <CircularProgress size={20} color="inherit" /> : action}</Button>}>
			<Box sx={{display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 0.25, mb: 1, fontSize: 13.5}}>
				<ButtonBase onClick={() => setCurrent(0)} sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5, px: 0.75, py: 0.25, borderRadius: 1, color: 'var(--u-text3)'}}><HomeOutlined sx={{fontSize: 16}} />{t('Files')}</ButtonBase>
				{content?.breadcrumb.map((b) => (
					<Box key={b.id} sx={{display: 'inline-flex', alignItems: 'center'}}>
						<ChevronRightOutlined sx={{fontSize: 16, color: 'var(--u-muted)'}} />
						<ButtonBase onClick={() => setCurrent(b.id)} sx={{px: 0.75, py: 0.25, borderRadius: 1, color: (b.id === current) ? 'var(--u-text)' : 'var(--u-text3)', fontWeight: (b.id === current) ? 600 : 400}}>{b.name}</ButtonBase>
					</Box>
				))}
			</Box>
			<Box sx={{height: 300, overflowY: 'auto', border: '1px solid var(--u-border)', borderRadius: 2, p: 0.5}}>
				{!content && !error && <Box sx={{display: 'flex', justifyContent: 'center', py: 4}}><CircularProgress size={24} /></Box>}
				{content && !content.folders.length && <Typography sx={{p: 2, fontSize: 13, color: 'var(--u-muted)'}}>{t('No folder')}</Typography>}
				{content?.folders.map((f) => {

					const off = excluded.includes(f.id)
					return (
						<ButtonBase key={f.id} disabled={off} onDoubleClick={() => setCurrent(f.id)} onClick={() => setCurrent(f.id)}
							sx={{width: '100%', justifyContent: 'flex-start', gap: 1, height: 36, px: 1.25, borderRadius: 1.5, fontSize: 13.5, opacity: off ? .45 : 1, '&:hover': {bgcolor: 'var(--u-hover)'}}}>
							<FolderRounded sx={{fontSize: 20, color: 'primary.main', opacity: .85}} />
							<Box component="span" sx={{flex: 1, textAlign: 'left', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{f.name}</Box>
							<ChevronRightOutlined sx={{fontSize: 18, color: 'var(--u-muted)'}} />
						</ButtonBase>
					)

				})}
			</Box>
			{content && !content.write && <Alert severity="info" sx={{mt: 1.5}}>{t('You cannot add files in this folder.')}</Alert>}
			{error && <Box role="alert" sx={{mt: 1.5, px: 2, py: 1.5, borderRadius: '6px', bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 14}}>{error}</Box>}
		</DriveDialog>
	)

}

export type ConflictChoice = 'replace' | 'keep_both' | 'skip'

/**
 * names already taken in the target: replace (files), keep both, skip
 */
export function ConflictDialog({open, names, replace, onClose, onChoose}: {open: boolean, names: string[], replace: boolean, onClose: () => void, onChoose: (choice: ConflictChoice) => void})
{

	return (
		<DriveDialog open={open} title={t('Names already used')} onClose={onClose}
			actions={<>
				<Button variant="outlined" onClick={() => onChoose('skip')}>{t('Skip')}</Button>
				<Button variant="outlined" onClick={() => onChoose('keep_both')}>{t('Keep both')}</Button>
				{replace && <Button variant="contained" onClick={() => onChoose('replace')}>{t('Replace')}</Button>}
			</>}>
			<Typography sx={{fontSize: 14, mb: 1}}>{t('These names already exist in the target folder:')}</Typography>
			<Box component="ul" sx={{m: 0, pl: 2.5, maxHeight: 180, overflowY: 'auto', fontSize: 13.5}}>{names.map((n) => <li key={n}>{n}</li>)}</Box>
		</DriveDialog>
	)

}

/**
 * confirmation of an action (move that changes the access, delete for good)
 */
export function ConfirmDialog({open, title, message, warning = '', label, danger = false, onClose, onConfirm}: {open: boolean, title: string, message: string, warning?: string, label: string, danger?: boolean, onClose: () => void, onConfirm: () => Promise<void>})
{

	const [busy, setBusy] = useState(false)

	const confirm = async () => {

		setBusy(true)
		try
		{

			await onConfirm()

		}
		finally
		{

			setBusy(false)

		}

	}

	return (
		<DriveDialog open={open} title={title} onClose={onClose} width="xs" center
			actions={<Button variant="contained" color={danger ? 'error' : 'primary'} onClick={confirm} disabled={busy}>{busy ? <CircularProgress size={20} color="inherit" /> : label}</Button>}>
			{warning && <Box role="alert" sx={{mb: 1.5, px: 2, py: 1.5, borderRadius: '6px', bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 14, fontWeight: 500}}>{warning}</Box>}
			<Typography sx={{fontSize: 14, color: 'text.secondary'}}>{message}</Typography>
		</DriveDialog>
	)

}
