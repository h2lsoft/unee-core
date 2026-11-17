import { useState, type ReactNode } from 'react'
import { Box, Divider, IconButton, Menu, MenuItem, Popover, Tooltip } from '@mui/material'
import {
	CheckBoxOutlined, CodeOutlined, FormatBoldOutlined, FormatItalicOutlined, FormatListBulletedOutlined, FormatListNumberedOutlined, FormatQuoteOutlined,
	HelpOutlineOutlined, HorizontalRuleOutlined, ImageOutlined, InsertLinkOutlined, IntegrationInstructionsOutlined, StrikethroughSOutlined, TableChartOutlined, TitleOutlined,
} from '@mui/icons-material'
import { EditorSelection, type EditorView } from '@uiw/react-codemirror'
import { t } from '../../i18n'

/**
 * text around the selection (or a placeholder selected when nothing is selected); the same marks around it are removed (toggle)
 */
export function wrap(view: EditorView, before: string, after: string, placeholder: string)
{

	view.dispatch(view.state.changeByRange((range) => {

		const doc = view.state.doc
		const outer = doc.sliceString(range.from - before.length, range.to + after.length)
		if(!range.empty && outer === before + doc.sliceString(range.from, range.to) + after)
		{

			return {
				changes: [{from: range.from - before.length, to: range.from}, {from: range.to, to: range.to + after.length}],
				range: EditorSelection.range(range.from - before.length, range.to - before.length),
			}

		}

		const text = range.empty ? placeholder : doc.sliceString(range.from, range.to)
		return {
			changes: {from: range.from, to: range.to, insert: before + text + after},
			range: EditorSelection.range(range.from + before.length, range.from + before.length + text.length),
		}

	}))
	view.focus()

}

/**
 * marker at the start of every line of the selection: added, or removed when every line has it; headings replace another level
 *
 * @param marker '- ', '> ', '- [ ] ', '# '... or 'numbered' (1. 2. 3.)
 */
function prefix(view: EditorView, marker: string)
{

	const state = view.state
	const lines: {from: number, text: string}[] = []
	for(const range of state.selection.ranges)
	{

		for(let n = state.doc.lineAt(range.from).number; n <= state.doc.lineAt(range.to).number; n++)
		{

			const line = state.doc.line(n)
			if(!lines.some((l) => l.from === line.from))lines.push({from: line.from, text: line.text})

		}

	}

	const heading = /^#{1,6}\s/.test(marker)
	const pattern = heading ? /^#{1,6}\s+/ : ((marker === 'numbered') ? /^\d+[.)]\s+/ : new RegExp('^' + marker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
	const all = lines.every((l) => (heading ? l.text.startsWith(marker) : pattern.test(l.text)))

	const changes = lines.map((l, i) => {

		const found = l.text.match(pattern)
		const remove = found ? found[0].length : 0
		const insert = all ? '' : ((marker === 'numbered') ? `${i + 1}. ` : marker)
		return {from: l.from, to: l.from + remove, insert}

	})
	view.dispatch({changes})
	view.focus()

}

/**
 * block on its own lines after the line of the cursor (code block, table, rule)
 */
function block(view: EditorView, text: string, select?: [number, number])
{

	const line = view.state.doc.lineAt(view.state.selection.main.head)
	const before = line.text.trim() ? '\n\n' : ''
	const at = line.text.trim() ? line.to : line.from
	const insert = `${before}${text}\n`
	view.dispatch({
		changes: {from: at, insert},
		selection: select ? EditorSelection.range(at + before.length + select[0], at + before.length + select[1]) : EditorSelection.cursor(at + insert.length),
	})
	view.focus()

}

export const markdownActions = {
	bold: (v: EditorView) => wrap(v, '**', '**', t('bold text')),
	italic: (v: EditorView) => wrap(v, '*', '*', t('italic text')),
	link: (v: EditorView) => {

		const range = v.state.selection.main
		const label = range.empty ? t('link text') : v.state.doc.sliceString(range.from, range.to)
		const insert = `[${label}](https://)`
		// the url selected, ready to be typed
		v.dispatch({changes: {from: range.from, to: range.to, insert}, selection: EditorSelection.range(range.from + label.length + 3, range.from + insert.length - 1)})
		v.focus()

	},
}

// syntax reminder of the help button
const SYNTAX: [string, string][] = [
	['# Heading 1 / ## Heading 2 / ### Heading 3', 'Headings'],
	['**bold**  *italic*  ~~strikethrough~~', 'Bold, italic, strikethrough'],
	['`code`', 'Inline code'],
	['```\\ncode block\\n```', 'Code block'],
	['- item  /  1. item', 'Bulleted / numbered list'],
	['  - sub item (2 spaces)', 'Nested list'],
	['- [ ] task  /  - [x] done', 'Task list'],
	['> quote', 'Quote'],
	['[text](https://...)', 'Link'],
	['![text](https://.../image.png)', 'Image'],
	['| A | B |\\n|---|---|\\n| 1 | 2 |', 'Table'],
	['---', 'Horizontal rule'],
]

/**
 * toolbar of the markdown editor: formats around the selection, line markers, blocks, syntax reminder
 */
export default function MarkdownToolbar({view}: {view: EditorView | null})
{

	const [headings, setHeadings] = useState<HTMLElement | null>(null)
	const [help, setHelp] = useState<HTMLElement | null>(null)

	const button = (label: string, icon: ReactNode, run: (v: EditorView) => void, shortcut = '') => (
		<Tooltip title={shortcut ? `${label} (${shortcut})` : label} key={label}>
			<span>
				<IconButton size="small" aria-label={label} disabled={!view} onMouseDown={(e) => e.preventDefault()} onClick={() => view && run(view)} sx={{borderRadius: 1.5, color: 'var(--u-text2)'}}>{icon}</IconButton>
			</span>
		</Tooltip>
	)
	const sep = (key: string) => <Divider key={key} orientation="vertical" flexItem sx={{mx: 0.5, my: 0.75}} />
	const icon = {fontSize: 19}

	return (
		<Box role="toolbar" aria-label={t('Markdown formatting')} sx={{display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 0.25, px: 1.5, py: 0.25, borderBottom: '1px solid var(--u-border)', bgcolor: 'var(--u-paper)'}}>
			<Tooltip title={t('Heading')}>
				<span>
					<IconButton size="small" aria-label={t('Heading')} aria-haspopup="menu" disabled={!view} onMouseDown={(e) => e.preventDefault()} onClick={(e) => setHeadings(e.currentTarget)} sx={{borderRadius: 1.5, color: 'var(--u-text2)'}}><TitleOutlined sx={icon} /></IconButton>
				</span>
			</Tooltip>
			<Menu open={!!headings} anchorEl={headings} onClose={() => setHeadings(null)}>
				{[1, 2, 3, 4].map((n) => (
					<MenuItem key={n} dense onClick={() => { setHeadings(null); if(view)prefix(view, `${'#'.repeat(n)} `) }} sx={{fontSize: 22 - n * 2, fontWeight: 600, minWidth: 160}}>{t('Heading [N]', {N: n})}</MenuItem>
				))}
			</Menu>
			{button(t('Bold'), <FormatBoldOutlined sx={icon} />, markdownActions.bold, 'Ctrl+B')}
			{button(t('Italic'), <FormatItalicOutlined sx={icon} />, markdownActions.italic, 'Ctrl+I')}
			{button(t('Strikethrough'), <StrikethroughSOutlined sx={icon} />, (v) => wrap(v, '~~', '~~', t('text')))}
			{button(t('Inline code'), <CodeOutlined sx={icon} />, (v) => wrap(v, '`', '`', 'code'))}
			{sep('s1')}
			{button(t('Bulleted list'), <FormatListBulletedOutlined sx={icon} />, (v) => prefix(v, '- '))}
			{button(t('Numbered list'), <FormatListNumberedOutlined sx={icon} />, (v) => prefix(v, 'numbered'))}
			{button(t('Task list'), <CheckBoxOutlined sx={icon} />, (v) => prefix(v, '- [ ] '))}
			{button(t('Quote'), <FormatQuoteOutlined sx={icon} />, (v) => prefix(v, '> '))}
			{sep('s2')}
			{button(t('Link'), <InsertLinkOutlined sx={icon} />, markdownActions.link, 'Ctrl+K')}
			{button(t('Image'), <ImageOutlined sx={icon} />, (v) => wrap(v, '![', '](https://)', t('description')))}
			{button(t('Code block'), <IntegrationInstructionsOutlined sx={icon} />, (v) => block(v, '```\ncode\n```', [4, 8]))}
			{button(t('Table'), <TableChartOutlined sx={icon} />, (v) => block(v, `| ${t('Column')} 1 | ${t('Column')} 2 |\n| --- | --- |\n| | |`, [2, 2 + t('Column').length + 2]))}
			{button(t('Horizontal rule'), <HorizontalRuleOutlined sx={icon} />, (v) => block(v, '---'))}
			<Box sx={{flex: 1}} />
			<Tooltip title={t('Markdown syntax')}>
				<IconButton size="small" aria-label={t('Markdown syntax')} onClick={(e) => setHelp(e.currentTarget)} sx={{borderRadius: 1.5, color: 'var(--u-text3)'}}><HelpOutlineOutlined sx={icon} /></IconButton>
			</Tooltip>
			<Popover open={!!help} anchorEl={help} onClose={() => setHelp(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}}
				slotProps={{paper: {sx: {p: 2, maxWidth: 520, borderRadius: '10px'}}}}>
				<Box component="table" sx={{borderCollapse: 'collapse', fontSize: 13, '& td': {py: 0.6, pr: 2, verticalAlign: 'top', borderBottom: '1px solid var(--u-border-soft)'}}}>
					<tbody>
						{SYNTAX.map(([code, label]) => (
							<tr key={label}>
								<td style={{color: 'var(--u-text3)', whiteSpace: 'nowrap'}}>{t(label)}</td>
								<td><Box component="code" sx={{fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: 12.5, whiteSpace: 'pre'}}>{t(code).replace(/\\n/g, '\n')}</Box></td>
							</tr>
						))}
					</tbody>
				</Box>
			</Popover>
		</Box>
	)

}
