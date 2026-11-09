import { useEffect, useState } from 'react'
import { Box, Button, ButtonBase, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, Typography } from '@mui/material'
import { CloseOutlined } from '@mui/icons-material'
import { api } from '../api'
import { getLanguage, t } from '../i18n'

type Announce = {id: number, title: string, content: string, link_label: string, link_url: string, date: string}

/**
 * announcements of the logged user (module Announce), opened once the backend is loaded, one after another (dots)
 * I understood = read for good (logged, never shown again) then the next one, the cross hides them until the next load
 * content: html cleaned by php (\Core\Sanitizer, profile basic) on save and on output
 */
export default function AnnounceBox()
{

	const [items, setItems] = useState<Announce[]>([])
	const [index, setIndex] = useState(0)
	const [open, setOpen] = useState(true)

	useEffect(() => {

		api<{items: Announce[]}>('announce-box/').then((data) => setItems(data.items)).catch(() => null)

	}, [])

	const current = items[Math.min(index, items.length - 1)]
	if(!current)return null

	const understood = () => {

		api(`announce-box/${current.id}/read/`, {method: 'POST'}).catch(() => null)
		const rest = items.filter((a) => a.id !== current.id)
		setItems(rest)
		setIndex((i) => Math.min(i, Math.max(0, rest.length - 1)))

	}

	const date = current.date ? new Intl.DateTimeFormat(getLanguage(), {dateStyle: 'medium'}).format(new Date(current.date.replace(' ', 'T'))) : ''
	const position = Math.min(index, items.length - 1)

	return (
		<Dialog open={open} onClose={(_, reason) => reason !== 'backdropClick' && setOpen(false)} maxWidth="sm" fullWidth slotProps={{paper: {sx: {maxWidth: 560}}}}>
			<DialogTitle component="div" sx={{display: 'flex', alignItems: 'flex-start', gap: 1.5, pr: 1.5, pb: 0.5}}>
				<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75, flexGrow: 1, minWidth: 0}}>
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
						<Box component="span" sx={{fontSize: 11, fontWeight: 600, px: 1, py: '2px', borderRadius: '10px', bgcolor: 'var(--u-accent-bg)', color: 'var(--u-accent-text)'}}>{t('Announcement')}</Box>
						{date && <Typography component="span" sx={{fontSize: 12, color: 'var(--u-muted)'}}>{date}</Typography>}
					</Box>
					<Typography component="h2" sx={{m: 0, fontSize: 17, fontWeight: 600, lineHeight: 1.35, overflowWrap: 'anywhere'}}>{current.title}</Typography>
				</Box>
				<IconButton aria-label={t('Close')} onClick={() => setOpen(false)}><CloseOutlined /></IconButton>
			</DialogTitle>

			<DialogContent sx={{pt: 1, pb: 0.5, minHeight: 150, fontSize: 14, color: 'var(--u-text2)', overflowWrap: 'anywhere', '& p': {m: '0 0 8px', lineHeight: 1.55}, '& ul, & ol': {m: '0 0 8px', pl: 2.5, lineHeight: 1.6}, '& a': {color: 'var(--u-accent-text)'}, '& img': {maxWidth: '100%', height: 'auto'}}}>
				<div dangerouslySetInnerHTML={{__html: current.content}} />
			</DialogContent>

			<DialogActions disableSpacing sx={{justifyContent: 'space-between', flexWrap: 'wrap', gap: 1.5, px: 2.5, pt: 1.75, pb: 2.25, borderTop: '1px solid var(--u-border-soft)'}}>
				<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75}}>
					{items.length > 1 && items.map((a, i) => (
						<ButtonBase key={a.id} onClick={() => setIndex(i)} aria-label={t('Announcement [N]', {N: i + 1})} aria-current={i === position ? 'true' : undefined}
							sx={{width: i === position ? 20 : 8, height: 8, borderRadius: '4px', bgcolor: i === position ? 'primary.main' : 'var(--u-border-strong)', transition: 'width .15s'}} />
					))}
					{items.length > 1 && <Typography component="span" sx={{ml: 0.75, fontSize: 12, color: 'var(--u-muted)'}}>{t('[N] of [TOTAL]', {N: position + 1, TOTAL: items.length})}</Typography>}
				</Box>
				<Box sx={{display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 1, ml: 'auto'}}>
					{current.link_url && <Button variant="outlined" component="a" href={current.link_url} target="_blank" rel="noopener noreferrer">{current.link_label || t('Learn more')}</Button>}
					<Button variant="contained" onClick={understood}>{t('I understood')}</Button>
				</Box>
			</DialogActions>
		</Dialog>
	)

}
