import { useEffect, useState } from 'react'
import { Box, CircularProgress, Dialog, DialogContent, DialogTitle, IconButton, Typography } from '@mui/material'
import { CloseOutlined } from '@mui/icons-material'
import { api, ApiException, type DetailPayload } from '../api'
import { t } from '../i18n'
import CodeEditor from './Code'
import { Badge } from './DataGrid'

/**
 * read only view of a record (GET /api/<module>/<id>/detail/): fields on two columns, wide fields (message, code) under them
 */
export default function DetailDialog({module, id, onClose}: {module: string, id: number | null, onClose: () => void})
{

	const [data, setData] = useState<DetailPayload | null>(null)
	const [error, setError] = useState('')

	useEffect(() => {

		if(id === null)return
		setData(null)
		setError('')
		api<DetailPayload>(`${module}/${id}/detail/`).then(setData).catch((e) => setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))

	}, [module, id])

	const short = data?.fields.filter((f) => !f.wide) ?? []
	const wide = data?.fields.filter((f) => f.wide && f.value !== '') ?? []

	return (
		<Dialog open={id !== null} onClose={(_, reason) => reason !== 'backdropClick' && onClose()} maxWidth="md" fullWidth slotProps={{paper: {sx: {maxWidth: {md: 760}}}}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 18, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{data?.title ?? ''}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{pb: 3}}>
				{!data && !error && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
				{error && <Typography color="error">{error}</Typography>}
				{data && (
					<>
						<Box component="dl" sx={{m: 0, display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '1fr 1fr'}, columnGap: 3.5}}>
							{short.map((f) => (
								<Box key={f.label} sx={{display: 'grid', gridTemplateColumns: '110px 1fr', gap: 1.5, py: 0.75, borderBottom: '1px solid var(--u-hover)', fontSize: 13.5}}>
									<Box component="dt" sx={{color: 'var(--u-muted)'}}>{f.label}</Box>
									<Box component="dd" sx={{m: 0, minWidth: 0, overflowWrap: 'anywhere'}}>{f.value === '' ? <Box component="span" sx={{color: 'var(--u-faint)'}}>-</Box> : (f.type === 'badge' ? <Badge value={f.value} color={f.color} /> : f.value)}</Box>
								</Box>
							))}
						</Box>
						{wide.map((f) => (
							<Box key={f.label} sx={{mt: 2}}>
								<Typography sx={{mb: 0.75, fontSize: 13, fontWeight: 600, color: 'var(--u-text2)'}}>{f.label}</Typography>
								{f.type === 'code'
									? <CodeEditor value={f.value} language={f.language} readOnly height={Math.min(320, 40 + f.value.split('\n').length * 18)} />
									: <Typography sx={{fontSize: 14, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}>{f.value}</Typography>}
							</Box>
						))}
					</>
				)}
			</DialogContent>
		</Dialog>
	)

}
