import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent, type KeyboardEvent, type MouseEvent, type ReactNode } from 'react'
import { Box, Button, ButtonBase, CircularProgress, Divider, IconButton, InputBase, ListItemIcon, ListItemText, Menu, MenuItem, Tooltip, Typography } from '@mui/material'
import {
	AudioFileOutlined, CheckOutlined, ChevronRightOutlined, CloseOutlined, ContentCopyOutlined, ContentPasteOutlined, CreateNewFolderOutlined, DeleteForeverOutlined, DeleteOutlineOutlined, DescriptionOutlined,
	DownloadOutlined, DriveFileMoveOutlined, EditOutlined, DriveFileRenameOutlineOutlined, ExpandMoreOutlined, FilterListOutlined, FolderOpenOutlined, FolderZipOutlined, GridViewOutlined, HomeOutlined, ImageOutlined,
	InfoOutlined, InsertDriveFileOutlined, LinkOutlined, MovieOutlined, PictureAsPdfOutlined, PushPinOutlined, RestoreFromTrashOutlined, SearchOutlined, SelectAllOutlined, SettingsOutlined, StarBorderOutlined,
	StarRounded, TextSnippetOutlined, UploadFileOutlined, ViewListOutlined, VisibilityOutlined, WarningAmberOutlined,
} from '@mui/icons-material'
import { t } from '../../i18n'
import { useToast } from '../Toast'
import FormDialog from '../FormDialog'
import DriveSidebar, { type Place } from './DriveSidebar'
import DriveItems, { hasFiles } from './DriveItems'
import DriveDetails from './DriveDetails'
import DrivePreview from './DrivePreview'
import DriveLinks from './DriveLinks'
import ShareDialog from './ShareDialog'
import { useUploads, UploadPanel } from './DriveUploads'
import { ConfirmDialog, ConflictDialog, FolderPickerDialog, NameDialog, type ConflictChoice } from './DriveDialogs'
import {
	DEFAULT_SORT, driveApi, driveSize, errorMessage, errorReason, isEditableImage, isText, itemCount, itemKey, readPrefs, ref, savePrefs, saveSort,
	type DriveFile, type DriveIndex, type DriveItem, type FolderContent, type ItemRef, type Prefs, type Sort, type SortCol,
} from './driveApi'

// options of the picker mode (DrivePicker.tsx)
export type PickerOptions = {
	// folder the navigation stays in (root=), null = whole drive
	root: number | null
	// extensions accepted, null = all
	accept: string[] | null
	multiple: boolean
	upload: boolean
	// file selected at the opening (current value of the field)
	preselect: number
	onPick: (files: DriveFile[]) => void
	onCancel: () => void
}

type Clipboard = {mode: 'copy' | 'cut', items: ItemRef[], keys: Set<string>} | null
type MenuState = {kind: 'item' | 'folder', x: number, y: number} | null
type Transfer = {mode: 'move' | 'copy', items: ItemRef[], target: number}

const BORDER = '1px solid var(--u-border)'

// filters by type (globals drive.filters): the known keys translated, the others shown as they are
const TYPE_LABELS: Record<string, () => string> = {image: () => t('Images'), pdf: () => t('PDF'), office: () => t('Office'), archive: () => t('Archives'), video: () => t('Videos'), audio: () => t('Audio'), text: () => t('Text')}
const TYPE_ICONS: Record<string, typeof ImageOutlined> = {image: ImageOutlined, pdf: PictureAsPdfOutlined, office: DescriptionOutlined, archive: FolderZipOutlined, video: MovieOutlined, audio: AudioFileOutlined, text: TextSnippetOutlined}
const typeLabel = (key: string) => TYPE_LABELS[key]?.() ?? (key.charAt(0).toUpperCase() + key.slice(1))
const TypeIcon = ({name, size = 18}: {name: string, size?: number}) => { const Icon = TYPE_ICONS[name] ?? InsertDriveFileOutlined; return <Icon sx={{fontSize: size}} /> }

// entry of a menu: icon, label, shortcut
function Entry({icon, label, shortcut, onClick, disabled = false, danger = false}: {icon: ReactNode, label: string, shortcut?: string, onClick: () => void, disabled?: boolean, danger?: boolean})
{

	return (
		<MenuItem onClick={onClick} disabled={disabled} dense sx={{minHeight: 34, fontSize: 13.5, color: danger ? 'error.main' : 'inherit'}}>
			<ListItemIcon sx={{color: danger ? 'error.main' : 'var(--u-text3)', minWidth: '30px !important'}}>{icon}</ListItemIcon>
			<ListItemText slotProps={{primary: {sx: {fontSize: 13.5}}}}>{label}</ListItemText>
			{shortcut && <Typography sx={{ml: 3, fontSize: 12, color: 'var(--u-muted)'}}>{shortcut}</Typography>}
		</MenuItem>
	)

}

/**
 * File Manager of the Drive (module drive and picker mode): sidebar, breadcrumb, list or grid, details, menus, uploads, keyboard shortcuts
 * display preferences in localStorage (view, details, sidebar width, sort of each folder)
 */
export default function FileManager({start = 0, picker = null, forcedView = null, onFolderChange}: {start?: number, picker?: PickerOptions | null, forcedView?: 'list' | 'grid' | null, onFolderChange?: (folder: number) => void})
{

	const toast = useToast()
	const [prefs, setPrefs] = useState<Prefs>(readPrefs)
	const [index, setIndex] = useState<DriveIndex | null>(null)
	const [error, setError] = useState('')

	const locked = picker?.root ?? null
	const [place, setPlace] = useState<Place>('folder')
	const [folder, setFolder] = useState(start)
	const [content, setContent] = useState<FolderContent | null>(null)
	const [items, setItems] = useState<DriveItem[]>([])
	const [loading, setLoading] = useState(true)
	const [filter, setFilter] = useState('')
	const [query, setQuery] = useState('')
	const [search, setSearch] = useState('')
	const [kind, setKind] = useState('')
	const [kindMenu, setKindMenu] = useState<HTMLElement | null>(null)
	const [version, setVersion] = useState(0)

	const [selected, setSelected] = useState<Set<string>>(new Set())
	const anchor = useRef<string | null>(null)
	const [clipboard, setClipboard] = useState<Clipboard>(null)
	const [menu, setMenu] = useState<MenuState>(null)
	const [dragging, setDragging] = useState(false)

	const [naming, setNaming] = useState<{kind: 'create' | 'rename', item?: DriveItem} | null>(null)
	const [choosing, setChoosing] = useState<{mode: 'move' | 'copy', items: ItemRef[]} | null>(null)
	const [conflict, setConflict] = useState<{transfer: Transfer, names: string[]} | null>(null)
	const [confirm, setConfirm] = useState<{title: string, message: string, warning?: string, label: string, danger?: boolean, run: () => Promise<void>} | null>(null)
	const [settings, setSettings] = useState<{id: number, name: string} | null>(null)
	const [preview, setPreview] = useState<{id: number, edit: boolean} | null>(null)
	const [link_count, setLinkCount] = useState<number | null>(null)
	const [share, setShare] = useState<{id: number, name: string} | null>(null)

	const root = useRef<HTMLDivElement>(null)
	const file_input = useRef<HTMLInputElement>(null)
	const view = forcedView ?? prefs.view
	const sort: Sort = prefs.sorts[`f${folder}`] ?? DEFAULT_SORT
	const rights = (place === 'folder') ? content?.rights : index?.rights
	const can_write = !picker && place === 'folder' && !!content?.rights.write
	const can_upload = place === 'folder' && !!content?.rights.write && (!picker || picker.upload)
	// editors: write right of the folder shown, the plugin right elsewhere (recent, favorites, search: the api checks the folder)
	const can_edit = !picker && place !== 'trash' && !!((place === 'folder') ? content?.rights.write : index?.rights.write)

	// start: rights, storage, pins
	const loadIndex = useCallback(() => {

		driveApi.index().then(setIndex).catch((e) => setError(errorMessage(e)))

	}, [])

	useEffect(loadIndex, [loadIndex])

	// filter of the folder sent to the api a moment after the typing
	// in the search: the search follows the typing, an empty field goes back to the last folder shown
	useEffect(() => {

		const timer = setTimeout(() => {

			const value = filter.trim()
			setQuery(value)
			if(place === 'search')
			{

				if(value)setSearch(value)
				else setPlace('folder')

			}

		}, 250)

		return () => clearTimeout(timer)

	}, [filter, place])

	// content of the place
	useEffect(() => {

		let alive = true
		setLoading(true)

		const located = (list: DriveItem[]) => {

			// picker locked in a folder: what is out of it is left out
			if(locked === null || !content)return list
			const base = content.breadcrumb.find((b) => b.id === locked) ? content.breadcrumb.slice(0, content.breadcrumb.findIndex((b) => b.id === locked) + 1).map((b) => b.name).join('/') : ''
			return base ? list.filter((i) => (i.location ?? '') === `/${base}` || (i.location ?? '').startsWith(`/${base}/`)) : list

		}

		// type filter of the lists the api does not filter (recent, favorites, trash): files only, the folders stay
		const extensions = kind ? (index?.filters[kind] ?? []) : null
		const typed = (list: DriveItem[]) => extensions ? list.filter((i) => i.type === 'folder' || extensions.includes(i.extension)) : list
		const done = (list: DriveItem[]) => { if(alive){ setItems(typed(list)); setLoading(false) } }
		const fail = (e: unknown) => { if(alive){ setLoading(false); toast(errorMessage(e), 'error') } }

		if(place === 'folder')
		{

			driveApi.folder(folder, sort, query, 1, kind).then((c) => {

				if(!alive)return
				setContent(c)
				setItems([...c.folders, ...c.files])
				setLoading(false)
				onFolderChange?.(folder)

			}).catch((e) => {

				if(!alive)return
				// folder gone or not allowed: back to the root
				setLoading(false)
				toast(errorMessage(e), 'error')
				if(folder !== (locked ?? 0))setFolder(locked ?? 0)

			})

		}
		else if(place === 'recent')driveApi.recent().then((r) => done(located(r.files))).catch(fail)
		else if(place === 'favorites')driveApi.favorites().then((r) => done(located(r.files))).catch(fail)
		else if(place === 'trash')driveApi.trashList().then((r) => done([...r.folders, ...r.files])).catch(fail)
		// public links: their own view (DriveLinks)
		else if(place === 'links')done([])
		else if(place === 'search')driveApi.search(search, kind).then((r) => { if(alive){ setItems(located([...r.folders, ...r.files])); setLoading(false) } }).catch(fail)

		return () => { alive = false }

	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [place, folder, sort.col, sort.dir, query, search, kind, version])

	// preselection of the picker: the file of the field selected once its folder is shown
	const preselected = useRef(false)
	useEffect(() => {

		if(!picker?.preselect || preselected.current || place !== 'folder' || !content)return
		if(items.some((i) => i.type === 'file' && i.id === picker.preselect))
		{

			preselected.current = true
			setSelected(new Set([`file:${picker.preselect}`]))

		}

	}, [items, content, place, picker])

	const reload = useCallback(() => setVersion((v) => v + 1), [])

	// keyboard kept on the File Manager when the focused row is gone (folder opened, list reloaded, dialog closed)
	useEffect(() => {

		// after the paint: the browser moves the focus of a removed element lazily
		const frame = requestAnimationFrame(() => {

			const active = document.activeElement
			if(!active || active === document.body || !active.isConnected)root.current?.focus({preventScroll: true})

		})

		return () => cancelAnimationFrame(frame)

	}, [items, naming, choosing, conflict, confirm, settings, kindMenu, preview, share])

	// picker locked in a folder: a folder opened out of it goes back to it
	useEffect(() => {

		if(locked === null || !content || place !== 'folder' || folder === locked)return
		if(!content.breadcrumb.some((b) => b.id === locked))setFolder(locked)

	}, [content, locked, place, folder])

	// files sent back by the picker, with the path of their folder
	const pick = (files: DriveFile[]) => {

		if(!picker || !files.length)return
		const here = (place === 'folder' && content) ? content.folder.path : ''
		picker.onPick(files.map((f) => ({...f, location: f.location ?? here})))

	}

	const uploads = useUploads(useCallback((target: number) => {

		if(place === 'folder' && target === folder)reload()
		loadIndex()

	}, [place, folder, reload, loadIndex]))

	// navigation ****************************************************************************************************

	const goFolder = (id: number) => {

		setPlace('folder')
		setFolder(id)
		setFilter('')
		setQuery('')
		setSelected(new Set())
		setMenu(null)

	}

	const goPlace = (next: Place) => {

		setPlace(next)
		setSelected(new Set())
		setMenu(null)

	}

	const parent = () => {

		if(place !== 'folder' || !content || folder === (locked ?? 0))return
		const crumbs = content.breadcrumb
		goFolder((crumbs.length > 1) ? crumbs[crumbs.length - 2].id : 0)

	}

	// the breadcrumb starts at the folder the picker is locked in
	const crumbs = useMemo(() => {

		if(!content)return []
		if(locked === null)return content.breadcrumb
		const at = content.breadcrumb.findIndex((b) => b.id === locked)
		return (at >= 0) ? content.breadcrumb.slice(at + 1) : content.breadcrumb

	}, [content, locked])

	const root_name = (locked !== null && content) ? (content.breadcrumb.find((b) => b.id === locked)?.name ?? t('Files')) : t('Files')

	// selection *****************************************************************************************************

	const isDisabled = useCallback((item: DriveItem) => !!picker?.accept && item.type === 'file' && !picker.accept.includes(item.extension), [picker])
	const selection = items.filter((i) => selected.has(itemKey(i)))
	const selectionFiles = selection.filter((i): i is DriveFile => i.type === 'file')

	const select = (item: DriveItem, e: MouseEvent) => {

		e.stopPropagation()
		setMenu(null)
		if(isDisabled(item))return

		const key = itemKey(item)
		const multiple = !picker || picker.multiple

		if(multiple && e.shiftKey && anchor.current)
		{

			const keys = items.map(itemKey)
			const [a, b] = [keys.indexOf(anchor.current), keys.indexOf(key)].sort((x, y) => x - y)
			if(a >= 0)setSelected(new Set(items.slice(a, b + 1).filter((i) => !isDisabled(i)).map(itemKey)))
			return

		}

		if(multiple && (e.ctrlKey || e.metaKey))
		{

			const next = new Set(selected)
			if(next.has(key))next.delete(key)
			else next.add(key)
			setSelected(next)

		}
		else setSelected(new Set([key]))

		anchor.current = key

	}

	const selectAll = () => {

		if(picker && !picker.multiple)return
		setSelected(new Set(items.filter((i) => !isDisabled(i)).map(itemKey)))
		setMenu(null)

	}

	// actions *******************************************************************************************************

	const download = (file: DriveFile) => {

		const link = document.createElement('a')
		link.href = `${file.url}?download=1`
		link.rel = 'noopener'
		document.body.appendChild(link)
		link.click()
		link.remove()

	}

	const open = (item: DriveItem) => {

		setMenu(null)
		if(place === 'trash')return
		if(item.type === 'folder')return goFolder(item.id)
		if(picker)return pick([item])
		openPreview(item)

	}

	// lightbox of the files of the list (preview, editors); the trashed files cannot be read
	const openPreview = (file: DriveFile, edit = false) => {

		setMenu(null)
		if(place === 'trash')return
		setPreview({id: file.id, edit})

	}

	// content saved by an editor: the file updated in the list, a copy shown by a reload
	const contentSaved = (file: DriveFile, copy: boolean) => {

		if(copy)reload()
		else setItems((list) => list.map((i) => (i.type === 'file' && i.id === file.id) ? {...i, ...file, location: i.location} : i))
		loadIndex()

	}

	const favorite = async (file: DriveFile) => {

		setMenu(null)
		try
		{

			const r = await driveApi.favorite(file.id, !file.favorite)
			setItems((list) => (place === 'favorites' && !r.favorite) ? list.filter((i) => itemKey(i) !== itemKey(file)) : list.map((i) => (itemKey(i) === itemKey(file)) ? {...file, favorite: r.favorite} : i))
			toast(r.favorite ? t('Added to favorites') : t('Removed from favorites'))

		}
		catch(e)
		{

			toast(errorMessage(e), 'error')

		}

	}

	// tags of a file (details panel); a click on a tag searches it in the whole drive
	const saveTags = async (file: DriveFile, tags: string[]) => {

		try
		{

			const r = await driveApi.setTags(file.id, tags)
			setItems((list) => list.map((i) => (itemKey(i) === itemKey(file)) ? {...file, tags: r.tags} : i))

		}
		catch(e)
		{

			toast(errorMessage(e), 'error')

		}

	}

	const searchTag = (tag: string) => {

		const value = /\s/.test(tag) ? `#"${tag}"` : `#${tag}`
		setFilter(value)
		setSearch(value)
		goPlace('search')

	}

	const clearSearch = () => {

		setFilter('')
		setQuery('')
		if(place === 'search')setPlace('folder')

	}

	const pin = async (id: number, pinned: boolean) => {

		setMenu(null)
		try
		{

			const r = await driveApi.pin(id, pinned)
			setIndex((i) => i ? {...i, pins: r.pins} : i)
			if(content && content.folder.id === id)setContent({...content, folder: {...content.folder, pinned}})
			toast(pinned ? t('Pinned in the sidebar') : t('Unpinned'))

		}
		catch(e)
		{

			toast(errorMessage(e), 'error')

		}

	}

	// a folder goes to the trash with all its content: confirmation asked
	const trash = (list: DriveItem[]) => {

		setMenu(null)
		if(!list.length || !can_write)return

		const run = async () => {

			try
			{

				const r = await driveApi.trash(list.map(ref))
				toast(t('[N] moved to the trash', {N: r.trashed}))
				setSelected(new Set())
				setConfirm(null)
				reload()

			}
			catch(e)
			{

				toast(errorMessage(e), 'error')

			}

		}

		const folders = list.filter((i) => i.type === 'folder')
		if(!folders.length)return run()

		setConfirm({
			title: t('Move to the trash'),
			warning: (folders.length === 1) ? t('All the files and subfolders of “[NAME]” go to the trash with it.', {NAME: folders[0].name}) : t('All the files and subfolders of the [N] folders go to the trash with them.', {N: folders.length}),
			message: t('They can be restored from the trash.'),
			label: t('Move to the trash'),
			danger: true,
			run,
		})

	}

	const restore = async (list: DriveItem[]) => {

		setMenu(null)
		try
		{

			const r = await driveApi.restore(list.map(ref))
			toast(t('[N] restored', {N: r.restored}))
			setSelected(new Set())
			reload()

		}
		catch(e)
		{

			toast(errorMessage(e), 'error')

		}

	}

	const purge = (list: DriveItem[] | 'all') => {

		setMenu(null)
		setConfirm({
			title: (list === 'all') ? t('Empty the trash') : t('Delete for good'),
			warning: t('All the files and subfolders will be deleted for good.'),
			message: t('The storage is freed. This action cannot be undone.'),
			label: (list === 'all') ? t('Empty the trash') : t('Delete for good'),
			danger: true,
			run: async () => {

				try
				{

					const r = await driveApi.purge((list === 'all') ? 'all' : list.map(ref))
					toast(t('[N] deleted for good, [SIZE] freed', {N: r.deleted, SIZE: driveSize(r.freed)}))
					setSelected(new Set())
					setConfirm(null)
					reload()
					loadIndex()

				}
				catch(e)
				{

					toast(errorMessage(e), 'error')

				}

			},
		})

	}

	// move or copy: a name taken asks what to do, a change of access asks a confirmation
	const transfer = async (job: Transfer, choice: ConflictChoice | '' = '', confirmed = false): Promise<void> => {

		try
		{

			const r = (job.mode === 'move') ? await driveApi.move(job.items, job.target, choice, confirmed) : await driveApi.copy(job.items, job.target, choice)
			const count = ('moved' in r) ? r.moved : r.copied
			toast((job.mode === 'move') ? t('[N] moved', {N: count}) : t('[N] copied', {N: count}))
			if(job.mode === 'move' && clipboard?.mode === 'cut')setClipboard(null)
			setChoosing(null)
			setConflict(null)
			setConfirm(null)
			setSelected(new Set())
			reload()
			if(job.mode === 'copy')loadIndex()

		}
		catch(e)
		{

			const reason = errorReason(e)
			if(reason === 'conflict')
			{

				const names = ((e as {error: {names?: string[]}}).error.names) ?? []
				setChoosing(null)
				setConflict({transfer: job, names})

			}
			else if(reason === 'access_change')
			{

				setChoosing(null)
				setConfirm({title: t('Access change'), message: errorMessage(e), label: t('Move'), run: () => transfer(job, choice, true)})

			}
			else
			{

				toast(errorMessage(e), 'error')
				throw e

			}

		}

	}

	const copyToClipboard = (mode: 'copy' | 'cut') => {

		setMenu(null)
		if(!selection.length || picker || place !== 'folder')return
		if(mode === 'cut' && !can_write)return
		setClipboard({mode, items: selection.map(ref), keys: new Set(selection.map(itemKey))})
		toast((mode === 'cut') ? t('[N] cut, paste them in a folder', {N: selection.length}) : t('[N] copied, paste them in a folder', {N: selection.length}), 'info')

	}

	const paste = () => {

		setMenu(null)
		if(!clipboard || !can_write)return
		transfer({mode: (clipboard.mode === 'cut') ? 'move' : 'copy', items: clipboard.items, target: folder}).catch(() => {})

	}

	const changeSort = (col: SortCol) => {

		const next: Sort = (sort.col === col) ? {col, dir: (sort.dir === 'asc') ? 'desc' : 'asc'} : {col, dir: 'asc'}
		setPrefs((p) => ({...p, sorts: {...p.sorts, [`f${folder}`]: next}}))
		saveSort(folder, next)

	}

	const setView = (next: 'list' | 'grid') => {

		setPrefs((p) => ({...p, view: next}))
		if(!forcedView)savePrefs({view: next})

	}

	const toggleDetails = () => {

		setPrefs((p) => ({...p, details: !p.details}))
		savePrefs({details: !prefs.details})

	}

	const importFiles = () => {

		setMenu(null)
		file_input.current?.click()

	}

	// keyboard: on the File Manager only, never while typing *******************************************************

	const keyDown = (e: KeyboardEvent) => {

		const target = e.target as HTMLElement
		if(['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable)return

		const ctrl = e.ctrlKey || e.metaKey
		const key = e.key.toLowerCase()
		const single = (selection.length === 1) ? selection[0] : null
		let handled = true

		if(ctrl && e.shiftKey && key === 'n'){ if(can_write)setNaming({kind: 'create'}) }
		else if(ctrl && key === 'a')selectAll()
		else if(ctrl && key === 'c')copyToClipboard('copy')
		else if(ctrl && key === 'x')copyToClipboard('cut')
		else if(ctrl && key === 'v')paste()
		else if(e.key === 'F2'){ if(single && can_write)setNaming({kind: 'rename', item: single}) }
		else if(e.key === 'Delete'){ if(place === 'trash' && selection.length)purge(selection); else trash(selection) }
		else if(e.key === ' '){ if(single?.type === 'file')openPreview(single) }
		else if(e.key === 'Enter'){ if(single)open(single); else if(picker && selectionFiles.length)pick(selectionFiles) }
		else if(e.key === 'Backspace')parent()
		else if(e.key === 'Escape'){ if(menu)setMenu(null); else setSelected(new Set()) }
		else handled = false

		if(handled)e.preventDefault()

	}

	// files dropped from the computer on the content
	const dropProps = can_upload ? {
		onDragOver: (e: DragEvent) => { if(hasFiles(e)){ e.preventDefault(); setDragging(true) } },
		onDragLeave: (e: DragEvent) => { if(e.currentTarget === e.target)setDragging(false) },
		onDrop: (e: DragEvent) => { if(hasFiles(e)){ e.preventDefault(); setDragging(false); uploads.add(Array.from(e.dataTransfer.files), folder) } },
	} : {}

	// menus *********************************************************************************************************

	const contextItem = (item: DriveItem, e: MouseEvent) => {

		e.preventDefault()
		e.stopPropagation()
		if(isDisabled(item))return
		if(!selected.has(itemKey(item)))setSelected(new Set([itemKey(item)]))
		setMenu({kind: 'item', x: e.clientX, y: e.clientY})
		root.current?.focus()

	}

	const contextArea = (e: MouseEvent) => {

		e.preventDefault()
		setSelected(new Set())
		setMenu({kind: 'folder', x: e.clientX, y: e.clientY})

	}

	const openFolderMenu = (e: MouseEvent<HTMLElement>) => {

		e.stopPropagation()
		const box = e.currentTarget.getBoundingClientRect()
		setSelected(new Set())
		setMenu({kind: 'folder', x: box.left, y: box.bottom + 4})

	}

	const itemMenu = () => {

		const single = (selection.length === 1) ? selection[0] : null
		const file = (single?.type === 'file') ? single : null
		const icon = {fontSize: 18}

		if(place === 'trash')return [
			<Entry key="restore" icon={<RestoreFromTrashOutlined sx={icon} />} label={t('Restore')} onClick={() => restore(selection)} />,
			<Entry key="purge" icon={<DeleteForeverOutlined sx={icon} />} label={t('Delete for good')} shortcut={t('Del')} onClick={() => purge(selection)} disabled={!index?.rights.delete} danger />,
		]

		const entries: ReactNode[] = []
		if(single?.type === 'folder')entries.push(<Entry key="open" icon={<FolderOpenOutlined sx={icon} />} label={t('Open')} shortcut={t('Enter')} onClick={() => open(single)} />)
		if(file)entries.push(<Entry key="preview" icon={<VisibilityOutlined sx={icon} />} label={t('Preview')} shortcut={t('Space')} onClick={() => openPreview(file)} />)
		if(file && !picker && (isText(file) || isEditableImage(file)))entries.push(<Entry key="edit" icon={<EditOutlined sx={icon} />} label={isText(file) ? t('Edit') : t('Edit the image')} disabled={!can_edit} onClick={() => openPreview(file, true)} />)
		if(file)entries.push(<Entry key="download" icon={<DownloadOutlined sx={icon} />} label={t('Download')} onClick={() => { setMenu(null); download(file) }} />)
		if(picker)
		{

			if(selectionFiles.length)entries.push(<Entry key="pick" icon={<FolderOpenOutlined sx={icon} />} label={t('Select')} shortcut={t('Enter')} onClick={() => pick(selectionFiles)} />)
			return entries

		}

		const write = can_write || (place !== 'folder')
		entries.push(<Divider key="d1" />)
		if(single && place === 'folder')entries.push(<Entry key="rename" icon={<DriveFileRenameOutlineOutlined sx={icon} />} label={t('Rename')} shortcut="F2" disabled={!can_write} onClick={() => { setMenu(null); setNaming({kind: 'rename', item: single}) }} />)
		entries.push(<Entry key="move" icon={<DriveFileMoveOutlined sx={icon} />} label={t('Move to...')} disabled={!write} onClick={() => { setMenu(null); setChoosing({mode: 'move', items: selection.map(ref)}) }} />)
		entries.push(<Entry key="copy" icon={<ContentCopyOutlined sx={icon} />} label={t('Copy to...')} onClick={() => { setMenu(null); setChoosing({mode: 'copy', items: selection.map(ref)}) }} />)
		if(file)entries.push(<Entry key="share" icon={<LinkOutlined sx={icon} />} label={t('Share by link...')} onClick={() => { setMenu(null); setShare({id: file.id, name: file.name}) }} />)
		if(file)entries.push(<Entry key="fav" icon={file.favorite ? <StarRounded sx={{...icon, color: '#e0a400'}} /> : <StarBorderOutlined sx={icon} />} label={file.favorite ? t('Remove from favorites') : t('Add to favorites')} onClick={() => favorite(file)} />)
		if(single?.type === 'folder')
		{

			const pinned = !!index?.pins.some((p) => p.id === single.id)
			entries.push(<Entry key="pin" icon={<PushPinOutlined sx={icon} />} label={pinned ? t('Unpin from the sidebar') : t('Pin in the sidebar')} onClick={() => pin(single.id, !pinned)} />)
			if(rights?.manage)entries.push(<Entry key="settings" icon={<SettingsOutlined sx={icon} />} label={t('Folder settings...')} onClick={() => { setMenu(null); setSettings({id: single.id, name: single.name}) }} />)

		}
		if(place === 'folder')
		{

			entries.push(<Divider key="d2" />)
			entries.push(<Entry key="trash" icon={<DeleteOutlineOutlined sx={icon} />} label={t('Move to the trash')} shortcut={t('Del')} disabled={!can_write} onClick={() => trash(selection)} danger />)

		}

		return entries

	}

	const folderMenu = () => {

		const icon = {fontSize: 18}
		if(place === 'trash')return [<Entry key="empty" icon={<DeleteForeverOutlined sx={icon} />} label={t('Empty the trash')} disabled={!index?.rights.delete || !items.length} onClick={() => purge('all')} danger />]
		if(place !== 'folder')return [<Entry key="all" icon={<SelectAllOutlined sx={icon} />} label={t('Select all')} shortcut="Ctrl+A" onClick={selectAll} disabled={!!picker && !picker.multiple} />]

		const entries: ReactNode[] = []
		if(!picker)entries.push(<Entry key="new" icon={<CreateNewFolderOutlined sx={icon} />} label={t('New folder')} shortcut="Ctrl+Maj+N" disabled={!can_write} onClick={() => { setMenu(null); setNaming({kind: 'create'}) }} />)
		if(!picker || picker.upload)entries.push(<Entry key="import" icon={<UploadFileOutlined sx={icon} />} label={t('Import files...')} disabled={!can_upload} onClick={importFiles} />)
		if(!picker)entries.push(<Entry key="paste" icon={<ContentPasteOutlined sx={icon} />} label={clipboard ? t('Paste ([N])', {N: clipboard.items.length}) : t('Paste')} shortcut="Ctrl+V" disabled={!can_write || !clipboard} onClick={paste} />)
		if(entries.length)entries.push(<Divider key="d1" />)
		entries.push(<Entry key="all" icon={<SelectAllOutlined sx={icon} />} label={t('Select all')} shortcut="Ctrl+A" onClick={selectAll} disabled={!!picker && !picker.multiple} />)
		if(!picker && folder > 0)
		{

			const pinned = !!content?.folder.pinned
			entries.push(<Entry key="pin" icon={<PushPinOutlined sx={icon} />} label={pinned ? t('Unpin from the sidebar') : t('Pin in the sidebar')} onClick={() => pin(folder, !pinned)} />)
			if(content?.rights.manage)entries.push(<Entry key="settings" icon={<SettingsOutlined sx={icon} />} label={t('Folder settings...')} onClick={() => { setMenu(null); setSettings({id: folder, name: content.folder.name}) }} />)

		}

		return entries

	}

	// render ********************************************************************************************************

	if(error)return <Box sx={{p: 4}}><Typography color="error">{error}</Typography></Box>

	const title = {recent: t('Recent'), favorites: t('Favorites'), trash: t('Trash'), search: t('Search: [Q]', {Q: search}), links: t('Public links'), folder: ''}[place]
	const count = (place === 'folder' && content) ? content.total.folders + content.total.files : items.length
	const more = (place === 'folder' && content) ? content.total.files - content.files.length : 0
	const bytes = selectionFiles.reduce((sum, f) => sum + f.size, 0)

	let status = (place === 'links') ? ((link_count === null) ? '' : t('[N] links', {N: link_count})) : itemCount(count)
	if(selection.length === 1)status += `, ${t('“[NAME]” selected', {NAME: selection[0].name})}${bytes ? ` (${driveSize(bytes)})` : ''}`
	else if(selection.length > 1)status += `, ${t('[N] selected', {N: selection.length})}${bytes ? ` (${driveSize(bytes)})` : ''}`

	const loadMore = () => {

		if(!content)return
		driveApi.folder(folder, sort, query, content.page + 1).then((c) => {

			setContent({...c, files: [...content.files, ...c.files]})
			setItems((list) => [...list, ...c.files])

		}).catch((e) => toast(errorMessage(e), 'error'))

	}

	return (
		<Box ref={root} tabIndex={-1} onKeyDown={keyDown} onClick={() => setMenu(null)}
			sx={{height: '100%', minHeight: 0, display: 'flex', flexDirection: 'column', bgcolor: 'var(--u-paper)', border: picker ? 0 : BORDER, borderRadius: 0, overflow: 'hidden', outline: 0, position: 'relative'}}>

			{/* toolbar: breadcrumb (the last folder opens the folder menu), filter, view, details */}
			<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75, px: 1.5, py: 1, borderBottom: BORDER, bgcolor: 'var(--u-toolbar)', flexWrap: 'wrap'}}>
				<Box component="nav" aria-label={t('Breadcrumb')} sx={{display: 'flex', alignItems: 'center', gap: 0.25, flex: 1, minWidth: 200, fontSize: 13.5, overflow: 'hidden'}}>
					{place === 'folder'
						? <>
							<ButtonBase onClick={() => (crumbs.length ? goFolder(locked ?? 0) : null)} onContextMenu={(e) => { if(!crumbs.length){ e.preventDefault(); openFolderMenu(e) } }}
								sx={{display: 'inline-flex', alignItems: 'center', gap: 0.75, px: 1, py: 0.5, borderRadius: 1.5, color: crumbs.length ? 'var(--u-text3)' : 'var(--u-text)', fontWeight: crumbs.length ? 400 : 600, '&:hover': {bgcolor: 'var(--u-hover)'}}}>
								<HomeOutlined sx={{fontSize: 17}} />{root_name}
							</ButtonBase>
							{!crumbs.length && <IconButton size="small" aria-label={t('Actions of the folder')} aria-haspopup="menu" onClick={openFolderMenu} sx={{ml: -0.5}}><ExpandMoreOutlined sx={{fontSize: 18}} /></IconButton>}
							{crumbs.map((b, i) => {

								const last = (i === crumbs.length - 1)
								return (
									<Box key={b.id} sx={{display: 'inline-flex', alignItems: 'center', minWidth: 0}}>
										<ChevronRightOutlined sx={{fontSize: 16, color: 'var(--u-muted)'}} />
										<ButtonBase onClick={last ? openFolderMenu : () => goFolder(b.id)} aria-haspopup={last ? 'menu' : undefined}
											sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5, px: 1, py: 0.5, borderRadius: 1.5, minWidth: 0, color: last ? 'var(--u-text)' : 'var(--u-text3)', fontWeight: last ? 600 : 400, bgcolor: (last && menu?.kind === 'folder') ? 'var(--u-hover)' : 'transparent', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
											<Box component="span" sx={{overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 220}}>{b.name}</Box>
											{last && <ExpandMoreOutlined sx={{fontSize: 16}} />}
										</ButtonBase>
									</Box>
								)

							})}
						</>
						: <Box sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5}}>
							<Typography sx={{px: 1, fontSize: 13.5, fontWeight: 600}}>{title}</Typography>
							{place === 'trash' && <IconButton size="small" aria-label={t('Actions of the trash')} aria-haspopup="menu" onClick={openFolderMenu}><ExpandMoreOutlined sx={{fontSize: 18}} /></IconButton>}
						</Box>}
				</Box>

				{/* filter of the folder (name, *.pdf, #tag), Enter = whole drive, Escape clears it */}
				{place !== 'links' && <Box component="form" role="search" onSubmit={(e) => { e.preventDefault(); if(filter.trim()){ setSearch(filter.trim()); goPlace('search') } }}
					sx={{display: 'flex', alignItems: 'center', gap: 0.5, height: 34, width: 270, pl: 1.25, pr: 0.5, bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border-strong)', borderRadius: 2, color: 'var(--u-muted)', '&:focus-within': {borderColor: 'primary.main'}}}>
					<SearchOutlined sx={{fontSize: 17}} />
					<InputBase value={filter} onChange={(e) => setFilter(e.target.value)} placeholder={t('Name, *.pdf, #tag')} title={t('Filter this folder, Enter to search the whole drive')}
						onKeyDown={(e) => { if(e.key === 'Escape' && filter){ e.preventDefault(); e.stopPropagation(); clearSearch() } }}
						inputProps={{'aria-label': t('Filter this folder, Enter to search the whole drive')}} sx={{flex: 1, fontSize: 13}} />
					{filter && <IconButton size="small" aria-label={t('Clear the search')} title={t('Clear the search')} onClick={clearSearch} sx={{p: 0.5}}><CloseOutlined sx={{fontSize: 16}} /></IconButton>}
				</Box>}

				{/* filter by type (globals drive.filters) */}
				{index && Object.keys(index.filters).length > 0 && place !== 'trash' && place !== 'links' && (
					<Tooltip title={kind ? t('Type: [TYPE]', {TYPE: typeLabel(kind)}) : t('Filter by type')}>
						<Button aria-label={kind ? t('Type: [TYPE]', {TYPE: typeLabel(kind)}) : t('Filter by type')} aria-haspopup="menu" onClick={(e) => setKindMenu(e.currentTarget)}
							startIcon={kind ? <TypeIcon name={kind} /> : <FilterListOutlined sx={{fontSize: 18}} />} endIcon={<ExpandMoreOutlined sx={{fontSize: 16}} />}
							sx={{height: 34, minWidth: 0, px: 1.25, borderRadius: 2, fontSize: 13, fontWeight: 500, color: kind ? 'var(--u-accent-text)' : 'var(--u-text3)', bgcolor: kind ? 'var(--u-accent-bg)' : 'transparent', '& .MuiButton-startIcon': {mr: kind ? 0.75 : 0}, '& .MuiButton-endIcon': {ml: 0.25}}}>
							{kind ? typeLabel(kind) : ''}
						</Button>
					</Tooltip>
				)}
				<Menu open={!!kindMenu} anchorEl={kindMenu} onClose={() => setKindMenu(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}}
					slotProps={{paper: {sx: {minWidth: 200, borderRadius: '10px', border: BORDER, boxShadow: '0 8px 24px rgba(16, 20, 40, .12)'}}, list: {dense: true, sx: {py: 0.5}}}}>
					{['', ...Object.keys(index?.filters ?? {})].map((key) => (
						<MenuItem key={key || 'all'} selected={kind === key} onClick={() => { setKind(key); setKindMenu(null); setSelected(new Set()) }} sx={{minHeight: 34, fontSize: 13.5, gap: 1.25}}>
							<Box sx={{display: 'flex', color: 'var(--u-text3)'}}>{key ? <TypeIcon name={key} /> : <FilterListOutlined sx={{fontSize: 18}} />}</Box>
							<Box component="span" sx={{flex: 1}}>{key ? typeLabel(key) : t('All types')}</Box>
							{kind === key && <CheckOutlined sx={{fontSize: 16, color: 'primary.main'}} />}
						</MenuItem>
					))}
				</Menu>

				{place !== 'links' && <>
				<Box sx={{width: '1px', height: 24, bgcolor: 'var(--u-border)', mx: 0.5}} />
				{!forcedView && <>
					<Tooltip title={t('List')}><IconButton aria-label={t('List')} onClick={() => setView('list')} sx={{borderRadius: 2, color: view === 'list' ? 'var(--u-accent-text)' : 'var(--u-text3)', bgcolor: view === 'list' ? 'var(--u-accent-bg)' : 'transparent'}}><ViewListOutlined sx={{fontSize: 20}} /></IconButton></Tooltip>
					<Tooltip title={t('Grid')}><IconButton aria-label={t('Grid')} onClick={() => setView('grid')} sx={{borderRadius: 2, color: view === 'grid' ? 'var(--u-accent-text)' : 'var(--u-text3)', bgcolor: view === 'grid' ? 'var(--u-accent-bg)' : 'transparent'}}><GridViewOutlined sx={{fontSize: 20}} /></IconButton></Tooltip>
				</>}
				<Tooltip title={t('Details')}><IconButton aria-label={t('Details')} onClick={toggleDetails} sx={{borderRadius: 2, color: prefs.details ? 'var(--u-accent-text)' : 'var(--u-text3)', bgcolor: prefs.details ? 'var(--u-accent-bg)' : 'transparent'}}><InfoOutlined sx={{fontSize: 20}} /></IconButton></Tooltip>
				</>}
			</Box>

			<Box sx={{flex: 1, display: 'flex', minHeight: 0}}>
				<DriveSidebar place={place} folder={folder} pins={index?.pins ?? []} usage={picker ? null : (index?.usage ?? null)} width={prefs.sidebar} locked={locked} picker={!!picker}
					onPlace={goPlace} onFolder={goFolder} onWidth={(w, save) => { setPrefs((p) => ({...p, sidebar: w})); if(save)savePrefs({sidebar: w}) }} />

				<Box onClick={() => { setSelected(new Set()); setMenu(null) }} onContextMenu={contextArea} {...dropProps}
					sx={{flex: 1, minWidth: 0, overflowY: 'auto', position: 'relative', outline: dragging ? '2px dashed var(--mui-palette-primary-main)' : 'none', outlineOffset: -6}}>
					{place === 'trash' && (
						<Box role="note" sx={{display: 'flex', alignItems: 'flex-start', gap: 1, m: '10px 10px 0', px: 1.75, py: 1.25, borderRadius: '8px', bgcolor: 'var(--u-orange-bg)', color: 'var(--u-orange)', fontSize: 13}}>
							<WarningAmberOutlined sx={{fontSize: 18, mt: '1px'}} />
							<Box>{t('A folder deleted for good takes all its files and subfolders with it.')}</Box>
						</Box>
					)}
					{loading && !items.length && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress size={26} /></Box>}
					{place === 'links' && <DriveLinks onFolder={goFolder} onCount={setLinkCount} />}
					{!loading && !items.length && place !== 'links' && (
						<Box sx={{py: 8, px: 2, textAlign: 'center', color: 'var(--u-muted)', fontSize: 13.5}}>
							{((place === 'folder' && query) || kind) ? t('Nothing matches this filter') : ({folder: can_upload ? t('Empty folder: drop files here or use the menu of the folder') : t('Empty folder'), recent: t('No recent file'), favorites: t('No favorite file'), trash: t('The trash is empty'), search: t('No result'), links: ''}[place])}
						</Box>
					)}
					<DriveItems items={items} view={view} sort={(place === 'folder') ? sort : null} onSort={changeSort} selected={selected} cut={(clipboard?.mode === 'cut') ? clipboard.keys : new Set()}
						located={place !== 'folder'} trash={place === 'trash'} disabled={isDisabled} more={more} onMore={loadMore}
						onSelect={select} onOpen={open} onContext={contextItem} onDropFiles={can_upload ? (target, files) => uploads.add(files, target) : null} />
					<UploadPanel queue={uploads.queue} onResolve={uploads.resolve} onCancel={uploads.cancel} onClear={uploads.clear} />
				</Box>

				{prefs.details && place !== 'links' && (
					<DriveDetails selection={selection} onClose={toggleDetails} onFavorite={favorite} onDownload={download} onTags={saveTags} onTag={searchTag} onPreview={(f) => openPreview(f)} onShare={picker ? undefined : (f) => setShare({id: f.id, name: f.name})}
						tagging={!picker && place !== 'trash' && !!((place === 'folder') ? content?.rights.write : index?.rights.write)}
						folder={(place === 'folder' && content) ? {name: content.folder.name, path: content.folder.path, count, public: content.folder.public} : null} />
				)}
			</Box>

			{/* status bar; picker: selection and buttons */}
			<Box sx={{display: 'flex', alignItems: 'center', gap: 2, px: 1.75, py: picker ? 1.25 : 1, borderTop: BORDER, bgcolor: 'var(--u-head)', fontSize: 12.5, color: 'var(--u-text3)'}}>
				<Box component="span" sx={{flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{status}</Box>
				{picker && <>
					<Button variant="outlined" onClick={picker.onCancel} sx={{height: 34, bgcolor: 'var(--u-paper)'}}>{t('Cancel')}</Button>
					<Button variant="contained" disabled={!selectionFiles.length} onClick={() => pick(selectionFiles)} sx={{height: 34}}>{(selectionFiles.length > 1) ? t('Select ([N])', {N: selectionFiles.length}) : t('Select')}</Button>
				</>}
			</Box>

			<input ref={file_input} type="file" multiple hidden onChange={(e) => { uploads.add(Array.from(e.target.files ?? []), folder); e.target.value = '' }} />

			<Menu open={!!menu} onClose={() => setMenu(null)} anchorReference="anchorPosition" anchorPosition={menu ? {top: menu.y, left: menu.x} : undefined}
				onClick={(e) => e.stopPropagation()} onContextMenu={(e) => { e.preventDefault(); setMenu(null) }}
				slotProps={{paper: {sx: {minWidth: 250, borderRadius: '10px', border: BORDER, boxShadow: '0 8px 24px rgba(16, 20, 40, .12)'}}, list: {dense: true, sx: {py: 0.5}}}}>
				{(menu?.kind === 'item') ? itemMenu() : folderMenu()}
			</Menu>

			<NameDialog open={!!naming} title={(naming?.kind === 'rename') ? t('Rename') : t('New folder')} value={(naming?.kind === 'rename') ? (naming.item?.name ?? '') : t('New folder')} file={naming?.item?.type === 'file'}
				onClose={() => setNaming(null)}
				onSubmit={async (name) => {

					if(naming?.kind === 'rename' && naming.item)await driveApi.rename(ref(naming.item), name)
					else await driveApi.createFolder(folder, name)
					setNaming(null)
					reload()

				}} />

			<FolderPickerDialog open={!!choosing} title={(choosing?.mode === 'move') ? t('Move to...') : t('Copy to...')} action={(choosing?.mode === 'move') ? t('Move here') : t('Copy here')}
				start={(place === 'folder') ? folder : 0} excluded={choosing?.items.filter((i) => i.type === 'folder').map((i) => i.id) ?? []}
				onClose={() => setChoosing(null)} onSubmit={(target) => choosing ? transfer({mode: choosing.mode, items: choosing.items, target}) : Promise.resolve()} />

			<ConflictDialog open={!!conflict} names={conflict?.names ?? []} replace={conflict?.transfer.mode === 'move'} onClose={() => setConflict(null)}
				onChoose={(choice) => conflict && transfer(conflict.transfer, choice).catch(() => {})} />

			<ConfirmDialog open={!!confirm} title={confirm?.title ?? ''} message={confirm?.message ?? ''} warning={confirm?.warning} label={confirm?.label ?? ''} danger={confirm?.danger} onClose={() => setConfirm(null)} onConfirm={() => confirm ? confirm.run().catch(() => {}) : Promise.resolve()} />

			{share && <ShareDialog file={share} onClose={() => setShare(null)} />}

			{preview && (() => {

				const files = items.filter((i): i is DriveFile => i.type === 'file' && !isDisabled(i))
				const at = files.findIndex((f) => f.id === preview.id)
				return (at < 0) ? null : <DrivePreview files={files} start={at} edit={preview.edit} editable={can_edit} avif={!!index?.avif} onClose={() => setPreview(null)}
					onFavorite={favorite} onDownload={download} onSaved={contentSaved} onShare={picker ? undefined : (f) => setShare({id: f.id, name: f.name})} />

			})()}

			{/* settings of a folder: form of the api, same look as the forms of the modules; keys kept in the dialog */}
			{settings && (
				<Box component="span" sx={{display: 'contents'}} onKeyDown={(e) => e.stopPropagation()} onClick={(e) => e.stopPropagation()}>
					<FormDialog open module="drive" id={0} label="" url={`drive/folders/${settings.id}/settings/`} title={t('Settings of [FOLDER]', {FOLDER: settings.name || t('Files')})}
						onClose={() => setSettings(null)} onSaved={() => { setSettings(null); reload() }} />
				</Box>
			)}
		</Box>
	)

}
