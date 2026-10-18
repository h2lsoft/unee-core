import { useEffect, useState } from 'react'
import { EditorContent, useEditor, useEditorState } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Highlight from '@tiptap/extension-highlight'
import { Box, Button, Divider, IconButton, OutlinedInput, Popover, Tooltip } from '@mui/material'
import {
	BorderColorOutlined, CloseFullscreenOutlined, CodeOutlined, FormatBoldOutlined, FormatClearOutlined, FormatItalicOutlined, FormatListBulletedOutlined,
	FormatListNumberedOutlined, FormatUnderlinedOutlined, InsertLinkOutlined, LinkOffOutlined, OpenInFullOutlined, StrikethroughSOutlined,
} from '@mui/icons-material'
import { t } from '../i18n'

const empty = (html: string) => (html === '<p></p>') ? '' : html

/**
 * html editor (tiptap): bold, underline, italic, strike, highlight, lists, links, source and full screen
 * the html is cleaned by the server (\Core\Sanitizer, profile of the field)
 */
export default function RichEditor({id, value, error = false, readOnly = false, onChange}: {id?: string, value: string, error?: boolean, readOnly?: boolean, onChange: (value: string) => void})
{

	const [source, setSource] = useState(false)
	const [full, setFull] = useState(false)
	const [link_anchor, setLinkAnchor] = useState<HTMLElement | null>(null)
	const [link, setLink] = useState('')

	const editor = useEditor({
		extensions: [
			StarterKit.configure({heading: false, codeBlock: false, blockquote: false, horizontalRule: false, link: {openOnClick: false, autolink: true, defaultProtocol: 'https'}}),
			Highlight,
		],
		content: value,
		editable: !readOnly,
		onUpdate: ({editor}) => onChange(empty(editor.getHTML())),
	})

	// value changed from outside (form loaded, source mode)
	useEffect(() => {

		if(!editor || editor.isDestroyed || !editor.isInitialized || source)return
		if(empty(editor.getHTML()) !== value)editor.commands.setContent(value, {emitUpdate: false})

	}, [editor, value, source])

	const state = useEditorState({
		editor,
		selector: ({editor: e}) => ({
			bold: e?.isActive('bold') ?? false,
			underline: e?.isActive('underline') ?? false,
			italic: e?.isActive('italic') ?? false,
			strike: e?.isActive('strike') ?? false,
			highlight: e?.isActive('highlight') ?? false,
			bullet: e?.isActive('bulletList') ?? false,
			ordered: e?.isActive('orderedList') ?? false,
			link: e?.isActive('link') ?? false,
		}),
	})

	if(!editor)return null

	const chain = () => editor.chain().focus()
	const button = (label: string, icon: React.ReactNode, onClick: (e: React.MouseEvent<HTMLButtonElement>) => void, active = false, disabled = false) => (
		<Tooltip title={label}>
			<span>
				<IconButton size="small" aria-label={label} aria-pressed={active} disabled={disabled || readOnly} onMouseDown={(e) => e.preventDefault()} onClick={onClick}
					sx={{width: 30, height: 30, borderRadius: 1.5, color: active ? 'primary.main' : '#3a3f55', bgcolor: active ? '#f0ecff' : 'transparent'}}>{icon}</IconButton>
			</span>
		</Tooltip>
	)
	const sep = <Divider orientation="vertical" flexItem sx={{mx: 0.5, my: 0.75}} />
	const in_source = source

	const applyLink = () => {

		const url = link.trim()
		if(url === '')chain().extendMarkRange('link').unsetLink().run()
		else chain().extendMarkRange('link').setLink({href: url}).run()
		setLinkAnchor(null)

	}

	return (
		<Box sx={full
			? {position: 'fixed', inset: 0, zIndex: 1400, bgcolor: '#fff', display: 'flex', flexDirection: 'column'}
			: {border: '1px solid', borderColor: error ? 'error.main' : '#d5d8e3', borderRadius: 2, overflow: 'hidden', bgcolor: '#fff', '&:focus-within': {borderColor: error ? 'error.main' : 'primary.main'}}}>
			<Box role="toolbar" aria-label={t('Format')} sx={{display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 0.25, px: 0.75, py: 0.5, bgcolor: '#f5f6f8', borderBottom: '1px solid #e6e8ee'}}>
				{button(t('Bold'), <FormatBoldOutlined fontSize="small" />, () => chain().toggleBold().run(), state?.bold, in_source)}
				{button(t('Underline'), <FormatUnderlinedOutlined fontSize="small" />, () => chain().toggleUnderline().run(), state?.underline, in_source)}
				{button(t('Italic'), <FormatItalicOutlined fontSize="small" />, () => chain().toggleItalic().run(), state?.italic, in_source)}
				{button(t('Strikethrough'), <StrikethroughSOutlined fontSize="small" />, () => chain().toggleStrike().run(), state?.strike, in_source)}
				{button(t('Highlight'), <BorderColorOutlined fontSize="small" />, () => chain().toggleHighlight().run(), state?.highlight, in_source)}
				{button(t('Clear formatting'), <FormatClearOutlined fontSize="small" />, () => chain().unsetAllMarks().clearNodes().run(), false, in_source)}
				{sep}
				{button(t('Bulleted list'), <FormatListBulletedOutlined fontSize="small" />, () => chain().toggleBulletList().run(), state?.bullet, in_source)}
				{button(t('Numbered list'), <FormatListNumberedOutlined fontSize="small" />, () => chain().toggleOrderedList().run(), state?.ordered, in_source)}
				{sep}
				{button(t('Link'), <InsertLinkOutlined fontSize="small" />, (e) => { setLink(editor.getAttributes('link').href ?? ''); setLinkAnchor(e.currentTarget) }, state?.link, in_source)}
				{button(t('Remove link'), <LinkOffOutlined fontSize="small" />, () => chain().extendMarkRange('link').unsetLink().run(), false, in_source || !state?.link)}
				{sep}
				{button(t('Source'), <CodeOutlined fontSize="small" />, () => setSource(!source), source)}
				{button(full ? t('Exit full screen') : t('Full screen'), full ? <CloseFullscreenOutlined fontSize="small" /> : <OpenInFullOutlined fontSize="small" />, () => setFull(!full), full)}
			</Box>

			{source
				? <Box component="textarea" id={id} value={value} readOnly={readOnly} onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => onChange(e.target.value)} spellCheck={false}
					sx={{display: 'block', width: '100%', minHeight: full ? 'calc(100vh - 42px)' : 220, boxSizing: 'border-box', p: 1.5, border: 0, outline: 0, resize: 'vertical', fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: 12.5, lineHeight: 1.6, color: '#1f2335'}} />
				: <Box sx={{flexGrow: 1, overflowY: 'auto', '& .tiptap': {minHeight: full ? 'calc(100vh - 42px)' : 220, boxSizing: 'border-box', px: 1.75, py: 1.25, outline: 0, fontSize: 14, lineHeight: 1.6}, '& .tiptap p': {m: '0 0 8px'}, '& .tiptap ul, & .tiptap ol': {pl: 3, m: '0 0 8px'}, '& .tiptap mark': {bgcolor: '#fff3a3', px: '2px'}, '& .tiptap a': {color: 'primary.main'}}}>
					<EditorContent editor={editor} id={id} />
				</Box>}

			<Popover open={!!link_anchor} anchorEl={link_anchor} onClose={() => setLinkAnchor(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'left'}} slotProps={{paper: {sx: {mt: 0.5, p: 1.25, display: 'flex', gap: 1, border: '1px solid #e6e8ee', boxShadow: '0 12px 32px rgba(16, 20, 40, 0.14)'}}}}>
				<OutlinedInput autoFocus size="small" value={link} onChange={(e) => setLink(e.target.value)} onKeyDown={(e) => { if(e.key === 'Enter'){ e.preventDefault(); applyLink() } }} placeholder="https://" inputProps={{'aria-label': t('Link')}} sx={{width: 280, height: 36}} />
				<Button variant="contained" onClick={applyLink} sx={{height: 36}}>{t('Apply')}</Button>
			</Popover>
		</Box>
	)

}
