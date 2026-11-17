import { useState, type DragEvent, type MouseEvent } from 'react'
import { Box, Button, ButtonBase, Chip, Typography } from '@mui/material'
import { ArrowDownwardOutlined, ArrowUpwardOutlined, FolderRounded, StarRounded } from '@mui/icons-material'
import { t } from '../../i18n'
import UserAvatar from '../UserAvatar'
import { FileIcon } from '../FileInput'
import { driveDate, driveSize, isViewableImage, itemCount, itemKey, type DriveFile, type DriveItem, type Sort, type SortCol } from './driveApi'

export type ItemsProps = {
	items: DriveItem[]
	view: 'list' | 'grid'
	// sortable headers (content of a folder)
	sort: Sort | null
	onSort: (col: SortCol) => void
	selected: Set<string>
	cut: Set<string>
	// location of the item shown under its name (recent, favorites, search, trash)
	located: boolean
	trash: boolean
	disabled: (item: DriveItem) => boolean
	more: number
	onMore: () => void
	onSelect: (item: DriveItem, e: MouseEvent) => void
	onOpen: (item: DriveItem) => void
	onContext: (item: DriveItem, e: MouseEvent) => void
	// files of the computer dropped on a folder
	onDropFiles: ((folder: number, files: File[]) => void) | null
}

const COLUMNS = 'minmax(0, 1fr) 110px 150px 56px'

// files dragged from the computer (not a text, not an item of the page)
export const hasFiles = (e: DragEvent) => Array.from(e.dataTransfer.types).includes('Files')

function ItemIcon({item, size}: {item: DriveItem, size: number})
{

	if(item.type === 'folder')return <FolderRounded sx={{fontSize: size, color: 'primary.main', flexShrink: 0, opacity: .85}} />
	return <FileIcon name={item.name} size={size} />

}

// badge of a favorite file, right of its name (list) or top right corner (grid)
function FavoriteBadge()
{

	return (
		<Box component="span" role="img" aria-label={t('Favorite')} title={t('Favorite')}
			sx={{display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 22, height: 22, flexShrink: 0, borderRadius: '50%', bgcolor: 'var(--u-yellow-bg)'}}>
			<StarRounded sx={{fontSize: 15, color: '#e0a400'}} />
		</Box>
	)

}

// tags of a file next to its name: the first 3, the count of the others
function Tags({tags}: {tags: string[]})
{

	const sx = {height: 18, flexShrink: 0, fontSize: 10.5, bgcolor: 'var(--u-border-soft)', '& .MuiChip-label': {px: 0.75}}
	return (
		<Box component="span" title={tags.join(', ')} sx={{display: 'inline-flex', gap: 0.5, minWidth: 0, overflow: 'hidden', flexShrink: 1}}>
			{tags.slice(0, 3).map((tag) => <Chip key={tag} label={tag} size="small" sx={sx} />)}
			{tags.length > 3 && <Chip label={`+${tags.length - 3}`} size="small" sx={sx} />}
		</Box>
	)

}

// author of an item (deleted by, in the trash): avatar, his name in the tooltip
function Author({item, trash}: {item: DriveItem, trash: boolean})
{

	const user = trash ? item.deleted_by : item.by
	if(!user)return <Box component="span" sx={{color: 'var(--u-faint)'}}>-</Box>
	return <UserAvatar avatar={user.avatar} title={user.name} size={26} />

}

/**
 * content shown by the File Manager: list (sortable columns) or grid (thumbnails of the images)
 * a click selects (Ctrl / Shift add), a double click opens, a right click opens the menu of the item
 */
export default function DriveItems(props: ItemsProps)
{

	const {items, view, sort, selected, cut, located, trash, disabled, more, onMore, onSelect, onOpen, onContext, onDropFiles} = props
	const [drop, setDrop] = useState<number | null>(null)

	// a folder takes the files of the computer dropped on it
	const dropProps = (item: DriveItem) => (item.type === 'folder' && onDropFiles) ? {
		onDragOver: (e: DragEvent) => { if(hasFiles(e)){ e.preventDefault(); e.stopPropagation(); setDrop(item.id) } },
		onDragLeave: () => setDrop(null),
		onDrop: (e: DragEvent) => { if(hasFiles(e)){ e.preventDefault(); e.stopPropagation(); setDrop(null); onDropFiles(item.id, Array.from(e.dataTransfer.files)) } },
	} : {}

	const state = (item: DriveItem) => {

		const key = itemKey(item)
		const off = disabled(item)
		return {
			key,
			off,
			on: selected.has(key),
			sx: {
				opacity: (off || cut.has(key)) ? .45 : 1,
				cursor: off ? 'default' : 'pointer',
				outline: (drop === item.id && item.type === 'folder') ? '2px dashed var(--mui-palette-primary-main)' : 'none',
				outlineOffset: -2,
			},
		}

	}

	const date = (item: DriveItem) => driveDate(trash ? (item.deleted_at ?? '') : item.date)
	const size = (item: DriveItem) => (item.type === 'folder') ? itemCount(item.count) : driveSize(item.size)

	const header = (col: SortCol, label: string, align: 'left' | 'right' | 'center' = 'left') => {

		const active = sort?.col === col
		const Arrow = (sort?.dir === 'desc') ? ArrowDownwardOutlined : ArrowUpwardOutlined
		const content = <>{label}{active && <Arrow sx={{fontSize: 13, color: 'primary.main'}} />}</>
		const sx = {display: 'flex', alignItems: 'center', gap: 0.5, justifyContent: (align === 'right') ? 'flex-end' : ((align === 'center') ? 'center' : 'flex-start'), fontSize: 12, fontWeight: 600, color: active ? 'var(--u-text)' : 'var(--u-muted)'}

		return sort
			? <ButtonBase onClick={() => props.onSort(col)} aria-label={t('Sort by [COLUMN]', {COLUMN: label})} sx={{...sx, height: 28, borderRadius: 1, '&:hover': {color: 'var(--u-text)'}}}>{content}</ButtonBase>
			: <Box sx={sx}>{label}</Box>

	}

	if(!items.length)return null

	const footer = more > 0 && (
		<Box sx={{display: 'flex', justifyContent: 'center', py: 1.5}}>
			<Button onClick={onMore} variant="outlined" size="small" sx={{height: 32}}>{t('Show [N] more', {N: more})}</Button>
		</Box>
	)

	if(view === 'grid')
	{

		return (
			<>
				<Box role="listbox" aria-multiselectable="true" sx={{p: 1.5, display: 'flex', flexWrap: 'wrap', gap: 0.75, alignContent: 'flex-start'}}>
					{items.map((item) => {

						const s = state(item)
						return (
							<ButtonBase key={s.key} role="option" aria-selected={s.on} aria-disabled={s.off} disableRipple
								onClick={(e) => onSelect(item, e)} onDoubleClick={() => !s.off && onOpen(item)} onContextMenu={(e) => onContext(item, e)} {...dropProps(item)}
								sx={{...s.sx, position: 'relative', width: 132, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 1, p: '12px 8px', borderRadius: 2, bgcolor: s.on ? 'var(--u-accent-bg)' : 'transparent', '&:hover': {bgcolor: s.on ? 'var(--u-accent-bg)' : 'var(--u-hover)'}}}>
								{item.type === 'file' && item.favorite && <Box sx={{position: 'absolute', top: 6, right: 6}}><FavoriteBadge /></Box>}
								{(item.type === 'file' && isViewableImage(item as DriveFile))
									? <Box component="img" src={`${(item as DriveFile).url}?v=${(item as DriveFile).hash}`} alt="" loading="lazy" sx={{width: 96, height: 64, objectFit: 'cover', borderRadius: 1.5, border: '1px solid var(--u-border)', bgcolor: 'var(--u-head)'}} />
									: <Box sx={{height: 64, display: 'flex', alignItems: 'center'}}><ItemIcon item={item} size={56} /></Box>}
								<Typography component="span" title={item.name} sx={{fontSize: 12.5, lineHeight: 1.35, maxWidth: 116, textAlign: 'center', wordBreak: 'break-word', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden'}}>{item.name}</Typography>
							</ButtonBase>
						)

					})}
				</Box>
				{footer}
			</>
		)

	}

	return (
		<Box sx={{p: '8px 10px'}}>
			<Box sx={{display: 'grid', gridTemplateColumns: COLUMNS, gap: 1.5, height: 34, alignItems: 'center', px: 1.75, borderBottom: '1px solid var(--u-border-soft)', mb: 0.5}}>
				{header('name', t('Name'))}
				{header('size', t('Size'), 'right')}
				{header('date', trash ? t('Deleted on') : t('Modified'), 'right')}
				{header('by', t('By'), 'center')}
			</Box>
			<Box role="listbox" aria-multiselectable="true" sx={{display: 'flex', flexDirection: 'column', gap: '1px'}}>
				{items.map((item) => {

					const s = state(item)
					const file = (item.type === 'file') ? item : null
					return (
						<ButtonBase key={s.key} role="option" aria-selected={s.on} aria-disabled={s.off} disableRipple
							onClick={(e) => onSelect(item, e)} onDoubleClick={() => !s.off && onOpen(item)} onContextMenu={(e) => onContext(item, e)} {...dropProps(item)}
							sx={{...s.sx, display: 'grid', gridTemplateColumns: COLUMNS, gap: 1.5, alignItems: 'center', minHeight: 40, px: 1.75, borderRadius: 1.5, textAlign: 'left', fontSize: 13.5, bgcolor: s.on ? 'var(--u-accent-bg)' : 'transparent', '&:hover': {bgcolor: s.on ? 'var(--u-accent-bg)' : 'var(--u-hover)'}}}>
							<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, minWidth: 0}}>
								<ItemIcon item={item} size={22} />
								<Box sx={{flex: 1, minWidth: 0}}>
									<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75, minWidth: 0}}>
										<Box component="span" sx={{flexShrink: 0, maxWidth: '100%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{item.name}</Box>
										{file && file.tags.length > 0 && <Tags tags={file.tags} />}
									</Box>
									{located && item.location !== undefined && <Box sx={{fontSize: 11.5, color: 'var(--u-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{item.location}</Box>}
								</Box>
								{file?.favorite && <FavoriteBadge />}
							</Box>
							<Box component="span" sx={{textAlign: 'right', fontSize: 13, color: 'var(--u-text3)', whiteSpace: 'nowrap'}}>{size(item)}</Box>
							<Box component="span" sx={{textAlign: 'right', fontSize: 13, color: 'var(--u-text3)', whiteSpace: 'nowrap'}}>{date(item)}</Box>
							<Box sx={{display: 'flex', justifyContent: 'center'}}><Author item={item} trash={trash} /></Box>
						</ButtonBase>
					)

				})}
			</Box>
			{footer}
		</Box>
	)

}
