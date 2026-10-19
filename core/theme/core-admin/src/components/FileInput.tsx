import { useRef, useState } from 'react'
import { Box, Button, CircularProgress, IconButton, Tooltip } from '@mui/material'
import { DeleteOutlineOutlined, DescriptionOutlined, FolderZipOutlined, ImageOutlined, InsertDriveFileOutlined, PictureAsPdfOutlined, SlideshowOutlined, TableChartOutlined, UploadFileOutlined } from '@mui/icons-material'
import { api, ApiException, type FormField } from '../api'
import { t } from '../i18n'

// 14 KB, 2.5 MB
export const fileSize = (bytes: number) => bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`

// icon of a file by its extension
export function FileIcon({name, size = 20}: {name: string, size?: number})
{

	const ext = name.split('.').pop()?.toLowerCase() ?? ''
	const sx = {fontSize: size, color: 'var(--u-text3)', flexShrink: 0}
	if(ext === 'pdf')return <PictureAsPdfOutlined sx={{...sx, color: 'error.main'}} />
	if(['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext))return <ImageOutlined sx={sx} />
	if(['xls', 'xlsx', 'ods', 'csv'].includes(ext))return <TableChartOutlined sx={{...sx, color: 'success.main'}} />
	if(['ppt', 'pptx', 'odp'].includes(ext))return <SlideshowOutlined sx={{...sx, color: 'warning.main'}} />
	if(['doc', 'docx', 'odt', 'txt'].includes(ext))return <DescriptionOutlined sx={{...sx, color: 'info.main'}} />
	if(['zip'].includes(ext))return <FolderZipOutlined sx={sx} />
	return <InsertDriveFileOutlined sx={sx} />

}

// names of the uploads not saved yet (token => name, size), kept when the field is rendered again
const uploads = new Map<string, {name: string, size: number}>()

/**
 * file field (\Component\Form::addFile): the file is uploaded at once (field.upload_url), the value becomes its token until the form is saved
 * value: path of the saved file, upload:<token>, '' = no file (removed on save)
 */
export default function FileInput({id, field, value, error, onChange}: {id: string, field: FormField, value: string, error: boolean, onChange: (value: string) => void})
{

	const input = useRef<HTMLInputElement>(null)
	const [busy, setBusy] = useState(false)
	const [message, setMessage] = useState('')
	const [over, setOver] = useState(false)

	const upload = async (file: File | undefined) => {

		if(!file || !field.upload_url)return
		setMessage('')
		if(field.max_size && file.size > field.max_size)return setMessage(t('the file is larger than [SIZE] MB', {SIZE: Math.round(field.max_size / 104857.6) / 10}))

		setBusy(true)
		try
		{

			const body = new FormData()
			body.append('file', file)
			const data = await api<{token: string, name: string, size: number}>(field.upload_url, {method: 'POST', body})
			uploads.set(data.token, {name: data.name, size: data.size})
			onChange(data.token)

		}
		catch(e)
		{

			setMessage(e instanceof ApiException ? e.error.message : t('network error, please retry'))

		}
		finally
		{

			setBusy(false)
			if(input.current)input.current.value = ''

		}

	}

	const pending = uploads.get(value)
	const name = pending?.name ?? value.split('/').pop() ?? ''
	const accept = field.extensions?.map((e) => `.${e}`).join(',')
	const hint = [field.extensions?.join(', '), field.max_size ? `${t('max')} ${fileSize(field.max_size)}` : ''].filter(Boolean).join(' - ')

	return (
		<Box>
			<input ref={input} id={id} type="file" hidden accept={accept} onChange={(e) => upload(e.target.files?.[0])} />
			{value === '' || busy
				? (
					<Box onDragOver={(e) => { e.preventDefault(); setOver(true) }} onDragLeave={() => setOver(false)} onDrop={(e) => { e.preventDefault(); setOver(false); if(!field.readonly)upload(e.dataTransfer.files?.[0]) }}
						sx={{display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap', minHeight: 34, px: 1.25, py: 0.75, border: '1px dashed', borderColor: error ? 'error.main' : (over ? 'primary.main' : 'var(--u-border-strong)'), borderRadius: 2, bgcolor: over ? 'var(--u-accent-bg)' : 'transparent'}}>
						{busy
							? <><CircularProgress size={18} /><Box component="span" sx={{fontSize: 13.5, color: 'var(--u-text2)'}}>{t('Uploading...')}</Box></>
							: (
								<>
									<Button size="small" variant="outlined" startIcon={<UploadFileOutlined />} disabled={field.readonly} onClick={() => input.current?.click()} sx={{height: 28, borderColor: 'var(--u-border-strong)', color: 'var(--u-text2)', fontWeight: 500}}>{t('Choose a file')}</Button>
									<Box component="span" sx={{fontSize: 13, color: 'var(--u-muted)'}}>{t('or drop it here')}</Box>
								</>
							)}
					</Box>
				)
				: (
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1, minHeight: 34, pl: 1.25, pr: 0.5, border: '1px solid', borderColor: error ? 'error.main' : 'var(--u-border-strong)', borderRadius: 2}}>
						<FileIcon name={name} />
						{(!pending && field.file_url)
							? <Box component="a" href={field.file_url} target="_blank" rel="noopener noreferrer" sx={{flexGrow: 1, minWidth: 0, fontSize: 14, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{name}</Box>
							: <Box component="span" sx={{flexGrow: 1, minWidth: 0, fontSize: 14, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{name}</Box>}
						{pending && <Box component="span" sx={{fontSize: 12, color: 'var(--u-muted)', whiteSpace: 'nowrap'}}>{fileSize(pending.size)}</Box>}
						{!field.readonly && (
							<>
								<Button size="small" onClick={() => input.current?.click()} sx={{height: 28, flexShrink: 0}}>{t('Replace')}</Button>
								<Tooltip title={t('Remove')}><IconButton size="small" aria-label={t('Remove')} onClick={() => onChange('')}><DeleteOutlineOutlined fontSize="small" /></IconButton></Tooltip>
							</>
						)}
					</Box>
				)}
			<Box sx={{mt: 0.5, fontSize: 12, color: message ? 'error.main' : 'var(--u-muted)'}}>{message || hint}</Box>
		</Box>
	)

}
