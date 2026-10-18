import { useEffect, useRef, useState } from 'react'
import { Box, Button, ButtonBase, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, InputLabel, Slider, useMediaQuery, useTheme } from '@mui/material'
import { CloseOutlined, CloudUploadOutlined, DeleteOutlineOutlined, InsertDriveFileOutlined } from '@mui/icons-material'
import { api, ApiException, type FormField } from '../api'
import { t } from '../i18n'
import { FIELDS_SX, Input } from './FormDialog'
import { useToast } from './Toast'

export type Step = {id: number, name: string, color: string, position: number, final: boolean}
export type Task = {id: number, step_id: number, user_id: number, user: {name: string, avatar: {initials: string, color: string, url: string}} | null, depends_on: number, category: string, title: string, excerpt: string, priority: string, progress: number, date_start: string | null, date_end: string | null, position: number, attachments: number, image: string | null}
type Attachment = {id: number, name: string, mime: string, size: number, url: string}

const MAX_SIZE = 20 * 1024 * 1024

const size = (bytes: number) => bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`
const ucfirst = (v: string) => v.charAt(0).toLocaleUpperCase() + v.slice(1)

// label on the left, control on the right (same rows as FormDialog)
function Row({label, html_for, required, children}: {label: string, html_for?: string, required?: boolean, children: React.ReactNode})
{

	return (
		<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '150px 1fr'}, columnGap: 2.5, rowGap: 0.5, alignItems: 'start'}}>
			<InputLabel htmlFor={html_for} sx={{fontSize: 14, fontWeight: 500, color: 'var(--u-text2)', textAlign: {xs: 'left', sm: 'right'}, whiteSpace: 'normal', lineHeight: {sm: '34px'}}}>
				{label}{required && <Box component="span" aria-hidden="true" sx={{color: 'error.main', ml: 0.5}}>*</Box>}
			</InputLabel>
			<Box sx={{minWidth: 0}}>{children}</Box>
		</Box>
	)

}

/**
 * add (id = 0) or edit dialog of a task of a project: fields, progress, attachments (uploaded after the creation for a new task)
 */
export default function TaskDialog({project, id, steps, tasks, users, categories, rights, defaults, onClose, onSaved}: {project: number, id: number, steps: Step[], tasks: Task[], users: {id: number, name: string}[], categories: string[], rights: string[], defaults?: Partial<Record<string, string>>, onClose: () => void, onSaved: () => void})
{

	const theme = useTheme()
	const full_screen = useMediaQuery(theme.breakpoints.down('md'))
	const toast = useToast()
	const input = useRef<HTMLInputElement>(null)
	const [values, setValues] = useState<Record<string, string> | null>(id ? null : {title: '', category: '', description: '', priority: 'normal', step_id: String(steps[0]?.id ?? ''), user_id: '', date_start: '', date_end: '', depends_on: '', progress: '0', ...defaults})
	const [attachments, setAttachments] = useState<Attachment[]>([])
	const [pending, setPending] = useState<File[]>([])
	const [errors, setErrors] = useState<Record<string, string[]>>({})
	const [saving, setSaving] = useState(false)
	const [uploading, setUploading] = useState(false)
	const [deleting, setDeleting] = useState(false)
	const [drag, setDrag] = useState(false)
	const can_edit = rights.includes(id ? 'edit' : 'add')

	const loadAttachments = () => api<{task: {attachments: Attachment[]}}>(`project/tasks/${id}/`).then((d) => setAttachments(d.task.attachments))

	useEffect(() => {

		if(!id)return
		api<{task: Task & {description: string, attachments: Attachment[]}}>(`project/tasks/${id}/`)
			.then(({task}) => {

				setValues({title: task.title, category: task.category, description: task.description, priority: task.priority, step_id: String(task.step_id), user_id: task.user_id ? String(task.user_id) : '', date_start: task.date_start ?? '', date_end: task.date_end ?? '', depends_on: task.depends_on ? String(task.depends_on) : '', progress: String(task.progress)})
				setAttachments(task.attachments)

			})
			.catch((e) => { toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error'); onClose() })

	}, [id])

	const set = (name: string) => (v: string) => setValues((old) => ({...old!, [name]: v}))

	const field = (name: string, type: FormField['type'], label: string, extra: Partial<FormField> = {}): FormField => ({name, type, label, required: false, width: 0, readonly: !can_edit, ...extra})

	const upload = async (task: number, files: File[]) => {

		setUploading(true)
		for(const file of files)
		{

			const body = new FormData()
			body.append('file', file)
			await api(`project/tasks/${task}/attachments/`, {method: 'POST', body}).catch((e) => toast(`${file.name}: ${e instanceof ApiException ? e.error.message : t('network error, please retry')}`, 'error'))

		}
		setUploading(false)

	}

	const addFiles = async (list: FileList | null) => {

		const files = Array.from(list ?? [])
		const too_big = files.filter((f) => f.size > MAX_SIZE)
		if(too_big.length)toast(t('the file is larger than [SIZE] MB', {SIZE: 20}), 'error')

		const ok = files.filter((f) => f.size <= MAX_SIZE)
		if(!ok.length)return
		if(!id)return setPending((old) => [...old, ...ok])

		await upload(id, ok)
		loadAttachments()
		onSaved()

	}

	const removeAttachment = async (attachment: Attachment) => {

		await api(`project/attachments/${attachment.id}/`, {method: 'DELETE'}).catch((e) => toast(e instanceof ApiException ? e.error.message : t('Error'), 'error'))
		loadAttachments()
		onSaved()

	}

	const submit = async (e: React.FormEvent) => {

		e.preventDefault()
		if(!values)return
		setSaving(true)
		setErrors({})

		try
		{

			const body = {...values, step_id: Number(values.step_id), user_id: Number(values.user_id || 0), depends_on: Number(values.depends_on || 0), progress: Number(values.progress)}
			const res = await api<{id: number}>(id ? `project/tasks/${id}/` : `project/${project}/tasks/`, {method: id ? 'PUT' : 'POST', body})
			if(!id && pending.length)await upload(res.id, pending)
			toast(t('Saved'))
			onSaved()
			onClose()

		}
		catch(err)
		{

			if(err instanceof ApiException && err.error.fields)setErrors(err.error.fields)
			else toast(err instanceof ApiException ? err.error.message : t('network error, please retry'), 'error')

		}
		finally
		{

			setSaving(false)

		}

	}

	const remove = async () => {

		setDeleting(false)
		try
		{

			await api(`project/tasks/${id}/`, {method: 'DELETE'})
			toast(t('Deleted'))
			onSaved()
			onClose()

		}
		catch(err)
		{

			toast(err instanceof ApiException ? err.error.message : t('network error, please retry'), 'error')

		}

	}

	const summary = Object.values(errors).flat()
	const others = tasks.filter((tk) => tk.id !== id)

	return (
		<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && onClose()} fullScreen={full_screen} maxWidth="lg" fullWidth slotProps={{paper: {component: 'form', onSubmit: submit, noValidate: true, sx: {maxWidth: {md: 860}}} as object}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 18, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{id ? t('Edit task') : t('Add task')}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>

			<DialogContent sx={{pt: 1}}>
				{!values && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
				{summary.length > 0 && (
					<Box role="alert" sx={{mb: 2.5, px: 2, py: 1.5, borderRadius: '6px', bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 14}}>
						<Box component="ul" sx={{m: 0, pl: 2.25}}>{summary.map((line) => <li key={line}>{line}</li>)}</Box>
					</Box>
				)}
				{values && (
					<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.75, py: 0.5, ...FIELDS_SX}}>
						<Row label={t('Title')} html_for="field-title" required>
							<Input field={field('title', 'text', t('Title'), {required: true, max_length: 255})} value={values.title} error={!!errors.title} onChange={(v) => set('title')(ucfirst(v))} />
						</Row>
						<Row label={t('Category')} html_for="field-category">
							<Input field={field('category', 'select', t('Category'), {autocomplete: true, free: true, max_length: 255, options: categories.map((c) => ({value: c, label: c}))})} value={values.category} error={!!errors.category} onChange={(v) => set('category')(ucfirst(v))} />
						</Row>
						<Row label={t('Description')} html_for="field-description">
							<Input field={field('description', 'html', t('Description'), {ucfirst: true})} value={values.description} error={false} onChange={set('description')} />
						</Row>
						<Row label={t('Priority')} required>
							<Input field={field('priority', 'radio', t('Priority'), {options: ['low', 'normal', 'high', 'urgent'].map((p) => ({value: p, label: t(p)}))})} value={values.priority} error={!!errors.priority} onChange={set('priority')} />
						</Row>
						<Row label={t('Step')} html_for="field-step_id" required>
							<Input field={field('step_id', 'select', t('Step'), {required: true, options: steps.map((s) => ({value: String(s.id), label: s.name}))})} value={values.step_id} error={!!errors.step_id} onChange={set('step_id')} />
						</Row>
						<Row label={t('Assigned to')} html_for="field-user_id">
							<Input field={field('user_id', 'select', t('Assigned to'), {autocomplete: true, options: users.map((u) => ({value: String(u.id), label: u.name}))})} value={values.user_id} error={!!errors.user_id} onChange={set('user_id')} />
						</Row>
						<Row label={t('Dates')} html_for="field-date_start">
							<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
								<Input field={field('date_start', 'date', t('Start date'))} value={values.date_start} error={!!errors.date_start} onChange={set('date_start')} />
								<Box component="span" sx={{fontSize: 13, color: 'var(--u-muted)'}}>{t('to')}</Box>
								<Input field={field('date_end', 'date', t('End date'))} value={values.date_end} error={!!errors.date_end} onChange={set('date_end')} />
							</Box>
						</Row>
						<Row label={t('Depends on')} html_for="field-depends_on">
							<Input field={field('depends_on', 'select', t('Depends on'), {autocomplete: true, options: others.map((tk) => ({value: String(tk.id), label: tk.title}))})} value={values.depends_on} error={!!errors.depends_on} onChange={set('depends_on')} />
						</Row>
						<Row label={t('Progress')}>
							<Box sx={{display: 'flex', alignItems: 'center', gap: 2, minHeight: 34, maxWidth: 420}}>
								<Slider size="small" step={5} min={0} max={100} value={Number(values.progress)} disabled={!can_edit} onChange={(_, v) => set('progress')(String(v))} aria-label={t('Progress')} sx={{flexGrow: 1}} />
								<Box component="span" sx={{width: 40, fontSize: 13, fontWeight: 600, textAlign: 'right', fontVariantNumeric: 'tabular-nums'}}>{values.progress}%</Box>
							</Box>
						</Row>
						<Row label={t('Attachments')}>
							<Box sx={{display: 'flex', flexDirection: 'column', gap: 1}}>
								{(attachments.length > 0 || pending.length > 0) && (
									<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '1fr 1fr'}, gap: 1}}>
										{attachments.map((a) => (
											<Box key={a.id} sx={{display: 'flex', alignItems: 'center', gap: 1, p: 0.75, border: '1px solid var(--u-border)', borderRadius: '5px', minWidth: 0}}>
												<Box component="a" href={`/api/${a.url}`} target="_blank" rel="noopener noreferrer" sx={{width: 36, height: 36, flexShrink: 0, borderRadius: '4px', overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center', bgcolor: 'var(--u-head)', color: 'var(--u-muted)'}}>
													{a.mime.startsWith('image/') && a.mime !== 'image/svg+xml' ? <Box component="img" src={`/api/${a.url}`} alt="" sx={{width: '100%', height: '100%', objectFit: 'cover'}} /> : <InsertDriveFileOutlined fontSize="small" />}
												</Box>
												<Box component="a" href={`/api/${a.url}`} target="_blank" rel="noopener noreferrer" sx={{flexGrow: 1, minWidth: 0, display: 'flex', flexDirection: 'column', color: 'var(--u-text)', textDecoration: 'none', '&:hover span:first-of-type': {textDecoration: 'underline'}}}>
													<Box component="span" sx={{fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{a.name}</Box>
													<Box component="span" sx={{fontSize: 11.5, color: 'var(--u-muted)'}}>{size(a.size)}</Box>
												</Box>
												{rights.includes('edit') && <IconButton size="small" aria-label={`${t('Delete')} ${a.name}`} onClick={() => removeAttachment(a)} sx={{color: 'var(--u-text3)'}}><DeleteOutlineOutlined sx={{fontSize: 18}} /></IconButton>}
											</Box>
										))}
										{pending.map((f, i) => (
											<Box key={`${f.name}-${i}`} sx={{display: 'flex', alignItems: 'center', gap: 1, p: 0.75, border: '1px dashed var(--u-border-strong)', borderRadius: '5px', minWidth: 0}}>
												<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--u-muted)'}}><InsertDriveFileOutlined fontSize="small" /></Box>
												<Box sx={{flexGrow: 1, minWidth: 0, display: 'flex', flexDirection: 'column'}}>
													<Box component="span" sx={{fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{f.name}</Box>
													<Box component="span" sx={{fontSize: 11.5, color: 'var(--u-muted)'}}>{size(f.size)} - {t('uploaded on save')}</Box>
												</Box>
												<IconButton size="small" aria-label={`${t('Delete')} ${f.name}`} onClick={() => setPending(pending.filter((_, j) => j !== i))} sx={{color: 'var(--u-text3)'}}><DeleteOutlineOutlined sx={{fontSize: 18}} /></IconButton>
											</Box>
										))}
									</Box>
								)}
								{can_edit && (
									<ButtonBase onClick={() => input.current?.click()} disabled={uploading}
										onDragOver={(e) => { e.preventDefault(); setDrag(true) }} onDragLeave={() => setDrag(false)} onDrop={(e) => { e.preventDefault(); setDrag(false); addFiles(e.dataTransfer.files) }}
										sx={{height: 56, gap: 1, border: '1px dashed', borderColor: drag ? 'primary.main' : 'var(--u-border-strong)', borderRadius: '5px', bgcolor: drag ? 'var(--u-accent-bg)' : 'transparent', color: 'var(--u-text3)', fontSize: 13}}>
										{uploading ? <CircularProgress size={18} /> : <CloudUploadOutlined fontSize="small" />}{t('Drop files or click to upload (20 MB max)')}
									</ButtonBase>
								)}
								<input ref={input} type="file" multiple hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.txt,.csv,.zip" onChange={(e) => { addFiles(e.target.files); e.target.value = '' }} />
							</Box>
						</Row>
					</Box>
				)}
			</DialogContent>

			<DialogActions sx={{px: 3, pt: 1, pb: 2.5, gap: 1}}>
				{id > 0 && rights.includes('delete') && <Button variant="outlined" color="error" onClick={() => setDeleting(true)} startIcon={<DeleteOutlineOutlined />} sx={{mr: 'auto', fontWeight: 500}}>{t('Delete')}</Button>}
				{can_edit && <Button type="submit" variant="contained" disabled={!values || saving || uploading}>{saving ? <CircularProgress size={20} color="inherit" /> : (id ? t('Save') : t('Create'))}</Button>}
			</DialogActions>

			<Dialog open={deleting} onClose={(_, reason) => reason !== 'backdropClick' && setDeleting(false)} maxWidth="xs" fullWidth>
				<DialogTitle sx={{display: 'flex', alignItems: 'center', pr: 1.5, fontSize: 18, fontWeight: 600}}>
					<Box component="span" sx={{flexGrow: 1}}>{t('Delete')}</Box>
					<IconButton aria-label={t('Close')} onClick={() => setDeleting(false)}><CloseOutlined /></IconButton>
				</DialogTitle>
				<DialogContent><Box sx={{fontSize: 14}}>{t('Delete this [LABEL]?', {LABEL: t('task')})}</Box></DialogContent>
				<DialogActions sx={{px: 3, pb: 2.5}}><Button variant="contained" color="error" onClick={remove}>{t('Delete')}</Button></DialogActions>
			</Dialog>
		</Dialog>
	)

}
