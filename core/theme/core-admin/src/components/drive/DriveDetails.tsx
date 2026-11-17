import { useEffect, useState, type ReactNode } from 'react'
import { Autocomplete, Box, Button, Chip, IconButton, TextField, Tooltip, Typography } from '@mui/material'
import { CloseOutlined, DownloadOutlined, FolderRounded, LinkOutlined, PublicOutlined, StarBorderOutlined, StarRounded, VisibilityOutlined } from '@mui/icons-material'
import { t } from '../../i18n'
import UserAvatar from '../UserAvatar'
import { FileIcon } from '../FileInput'
import { driveApi, driveDate, driveSize, isViewableImage, itemCount, type DriveFile, type DriveItem, type DriveUser } from './driveApi'

// text files shown in the preview: first characters only
const TEXT_PREVIEW = 4000

function Row({label, children}: {label: string, children: ReactNode})
{

	return (
		<>
			<Box component="dt" sx={{color: 'var(--u-muted)'}}>{label}</Box>
			<Box component="dd" sx={{m: 0, minWidth: 0, overflowWrap: 'anywhere'}}>{children}</Box>
		</>
	)

}

function Person({user}: {user: DriveUser | null | undefined})
{

	if(!user)return <>-</>
	return <Box component="span" sx={{display: 'inline-flex', alignItems: 'center', gap: 1}}><UserAvatar avatar={user.avatar} size={22} />{user.name}</Box>

}

/**
 * preview of a file: image, pdf, audio, video, beginning of a text file, its icon otherwise
 */
function Preview({file, onOpen}: {file: DriveFile, onOpen: () => void})
{

	const [text, setText] = useState<string | null>(null)
	const is_text = ['txt', 'md', 'csv', 'json', 'xml'].includes(file.extension)

	useEffect(() => {

		setText(null)
		if(!is_text)return

		const controller = new AbortController()
		fetch(`${file.url}?v=${file.hash}`, {credentials: 'same-origin', signal: controller.signal, headers: {Range: `bytes=0-${TEXT_PREVIEW * 4}`}})
			.then((r) => r.ok ? r.text() : '')
			.then((body) => setText(body.slice(0, TEXT_PREVIEW)))
			.catch(() => {})

		return () => controller.abort()

	}, [file.url, file.hash, is_text])

	const box = {height: 180, borderRadius: 2, bgcolor: 'var(--u-head)', border: '1px solid var(--u-border)', overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center'}

	// a click opens the lightbox
	const open = {component: 'button' as const, type: 'button' as const, onClick: onOpen, title: t('Preview'), 'aria-label': t('Preview of [NAME]', {NAME: file.name}), sx: {...box, width: '100%', p: 0, cursor: 'zoom-in', font: 'inherit', '&:hover': {borderColor: 'var(--u-accent-border)'}}}

	if(isViewableImage(file))return <Box {...open}><Box component="img" src={`${file.url}?v=${file.hash}`} alt="" sx={{maxWidth: '100%', maxHeight: '100%', objectFit: 'contain'}} /></Box>
	if(file.extension === 'pdf' || file.mime.startsWith('video/') || file.mime.startsWith('audio/'))return (
		<Box sx={{...box, flexDirection: 'column', gap: 1.25}}>
			<FileIcon name={file.name} size={56} />
			<Button variant="outlined" size="small" startIcon={<VisibilityOutlined />} onClick={onOpen} sx={{height: 30}}>{t('Preview')}</Button>
		</Box>
	)
	if(is_text)return <Box component="pre" onClick={onOpen} title={t('Preview')} sx={{...box, cursor: 'zoom-in', display: 'block', m: 0, p: 1.25, fontSize: 11.5, lineHeight: 1.5, fontFamily: 'ui-monospace, Menlo, Consolas, monospace', whiteSpace: 'pre-wrap', overflow: 'auto', color: 'var(--u-text2)'}}>{text ?? ''}</Box>

	return (
		<Box sx={{...box, flexDirection: 'column', gap: 1}}>
			<FileIcon name={file.name} size={56} />
			<Typography sx={{fontSize: 12, color: 'var(--u-muted)'}}>{t('No preview')}</Typography>
		</Box>
	)

}

/**
 * tags of a file: chips (a click searches the tag), added with the suggestions of the drive when the user can write
 */
function FileTags({file, editable, onChange, onTag}: {file: DriveFile, editable: boolean, onChange: (file: DriveFile, tags: string[]) => void, onTag: (tag: string) => void})
{

	const [options, setOptions] = useState<string[] | null>(null)
	const load = () => { if(options === null)driveApi.tags().then((r) => setOptions(r.tags)).catch(() => setOptions([])) }
	const chip = {height: 22, fontSize: 12}

	if(!editable)return file.tags.length
		? <Box sx={{display: 'flex', flexWrap: 'wrap', gap: 0.5}}>{file.tags.map((tag) => <Chip key={tag} label={tag} size="small" onClick={() => onTag(tag)} title={t('Search this tag')} sx={chip} />)}</Box>
		: <Box component="span" sx={{color: 'var(--u-faint)'}}>-</Box>

	return (
		<Autocomplete multiple freeSolo size="small" options={options ?? []} value={file.tags} onOpen={load} filterSelectedOptions
			onChange={(_, list) => {

				const tags = [...new Set(list.map((w) => String(w).trim().replace(/^#+/, '')).filter(Boolean))]
				setOptions((o) => o ? [...new Set([...o, ...tags])].sort() : o)
				onChange(file, tags)

			}}
			renderValue={(list, getItemProps) => list.map((tag, index) => <Chip {...getItemProps({index})} key={tag} label={tag} size="small" onClick={() => onTag(tag)} title={t('Search this tag')} sx={chip} />)}
			renderInput={(params) => <TextField {...params} placeholder={file.tags.length ? '' : t('Add a tag')} slotProps={{...params.slotProps, htmlInput: {...params.slotProps?.htmlInput, 'aria-label': t('Tags'), maxLength: 64}}}
				sx={{'&& .MuiOutlinedInput-root': {minHeight: 34, py: '3px', fontSize: 13}}} />}
		/>
	)

}

/**
 * details panel: the selected item (preview, infos, favorite, download), the selection (count, size) or the current folder
 */
export default function DriveDetails({selection, folder, tagging, onClose, onFavorite, onDownload, onTags, onTag, onPreview, onShare}: {
	selection: DriveItem[]
	folder: {name: string, path: string, count: number, public: boolean} | null
	// tags editable (write right)
	tagging: boolean
	onClose: () => void
	onFavorite: (file: DriveFile) => void
	onDownload: (file: DriveFile) => void
	onTags: (file: DriveFile, tags: string[]) => void
	onTag: (tag: string) => void
	onPreview: (file: DriveFile) => void
	// share by link (not in the picker)
	onShare?: (file: DriveFile) => void
})
{

	const one = (selection.length === 1) ? selection[0] : null
	const bytes = selection.reduce((sum, item) => sum + ((item.type === 'file') ? item.size : 0), 0)
	const grid = {display: 'grid', gridTemplateColumns: '96px minmax(0, 1fr)', rowGap: 1, columnGap: 1, m: 0, fontSize: 13}

	return (
		<Box component="aside" aria-label={t('Details')} sx={{width: 290, flex: '0 0 290px', boxSizing: 'border-box', display: {xs: 'none', lg: 'flex'}, flexDirection: 'column', gap: 1.75, p: '12px 16px 16px', borderLeft: '1px solid var(--u-border)', overflowY: 'auto', minHeight: 0}}>
			<Box sx={{display: 'flex', alignItems: 'center'}}>
				<Typography sx={{flex: 1, fontSize: 12, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--u-muted)'}}>{t('Details')}</Typography>
				<IconButton aria-label={t('Close')} onClick={onClose} size="small"><CloseOutlined sx={{fontSize: 18}} /></IconButton>
			</Box>

			{one?.type === 'file' && (
				<>
					<Preview file={one} onOpen={() => onPreview(one)} />
					<Box sx={{display: 'flex', alignItems: 'flex-start', gap: 1}}>
						<Typography sx={{flex: 1, fontWeight: 600, fontSize: 14.5, overflowWrap: 'anywhere'}}>{one.name}</Typography>
						<Tooltip title={one.favorite ? t('Remove from favorites') : t('Add to favorites')}>
							<IconButton size="small" aria-label={one.favorite ? t('Remove from favorites') : t('Add to favorites')} onClick={() => onFavorite(one)} sx={{mt: -0.5}}>
								{one.favorite ? <StarRounded sx={{fontSize: 20, color: '#e0a400'}} /> : <StarBorderOutlined sx={{fontSize: 20}} />}
							</IconButton>
						</Tooltip>
					</Box>
					<Box component="dl" sx={grid}>
						<Row label={t('Type')}>{one.extension.toUpperCase()} <Box component="span" sx={{color: 'var(--u-muted)'}}>({one.mime})</Box></Row>
						<Row label={t('Size')}>{driveSize(one.size)}</Row>
						<Row label={t('Modified')}>{driveDate(one.date)}</Row>
						<Row label={t('By')}><Person user={one.by} /></Row>
						{one.location !== undefined && <Row label={t('Location')}>{one.location}</Row>}
						{folder?.public && <Row label={t('Access')}><Box component="span" sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5}}><PublicOutlined sx={{fontSize: 16}} />{t('Public, without logon')}</Box></Row>}
					</Box>
					<Box>
						<Typography component="h3" sx={{mb: 0.75, fontSize: 12, fontWeight: 600, color: 'var(--u-muted)'}}>{t('Tags')}</Typography>
						<FileTags key={one.id} file={one} editable={tagging} onChange={onTags} onTag={onTag} />
					</Box>
					<Box sx={{display: 'flex', gap: 1}}>
						<Button variant="outlined" startIcon={<DownloadOutlined />} onClick={() => onDownload(one)} sx={{height: 34}}>{t('Download')}</Button>
						{onShare && <Button variant="outlined" startIcon={<LinkOutlined />} onClick={() => onShare(one)} sx={{height: 34}}>{t('Share')}</Button>}
					</Box>
				</>
			)}

			{one?.type === 'folder' && (
				<>
					<Box sx={{height: 120, borderRadius: 2, bgcolor: 'var(--u-head)', border: '1px solid var(--u-border)', display: 'flex', alignItems: 'center', justifyContent: 'center'}}><FolderRounded sx={{fontSize: 64, color: 'primary.main', opacity: .85}} /></Box>
					<Typography sx={{fontWeight: 600, fontSize: 14.5, overflowWrap: 'anywhere'}}>{one.name}</Typography>
					<Box component="dl" sx={grid}>
						<Row label={t('Content')}>{itemCount(one.count)}</Row>
						<Row label={t('Modified')}>{driveDate(one.date)}</Row>
						<Row label={t('By')}><Person user={one.by} /></Row>
						{one.location !== undefined && <Row label={t('Location')}>{one.location}</Row>}
					</Box>
				</>
			)}

			{selection.length > 1 && (
				<Box sx={{display: 'flex', flexDirection: 'column', gap: 1, fontSize: 13}}>
					<Typography sx={{fontWeight: 600, fontSize: 14.5}}>{t('[N] items selected', {N: selection.length})}</Typography>
					{bytes > 0 && <Box sx={{color: 'var(--u-text3)'}}>{t('Total size: [SIZE]', {SIZE: driveSize(bytes)})}</Box>}
				</Box>
			)}

			{!selection.length && folder && (
				<>
					<Typography sx={{fontWeight: 600, fontSize: 14.5, overflowWrap: 'anywhere'}}>{folder.name || t('Files')}</Typography>
					<Box component="dl" sx={grid}>
						<Row label={t('Content')}>{itemCount(folder.count)}</Row>
						<Row label={t('Location')}>{folder.path}</Row>
						{folder.public && <Row label={t('Access')}><Box component="span" sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5}}><PublicOutlined sx={{fontSize: 16}} />{t('Public, without logon')}</Box></Row>}
					</Box>
				</>
			)}
		</Box>
	)

}
