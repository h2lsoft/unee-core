import { useEffect, useState } from 'react'
import { Box, CircularProgress, InputBase, Tab, Tabs, Typography } from '@mui/material'
import { SearchOutlined } from '@mui/icons-material'
import { api, ApiException } from '../api'
import { t } from '../i18n'
import BookmarkButton from '../components/BookmarkButton'
import { MenuIcon } from '../components/icons'

type Pair = {name: string, value: string}
type SystemInfo = {
	title: string
	server: {stats: {label: string, value: string}[], variables: Pair[]}
	php: {stats: {label: string, value: string}[], ini: Pair[], extensions: string[], env: Pair[]}
	database: {version: string, variables: Pair[]}
}

const BORDER = '#e6e8ee'

// section with a name / value table, rows filtered by the search
function PairTable({title, pairs, filter}: {title: string, pairs: Pair[], filter: string})
{

	const rows = filter ? pairs.filter((p) => `${p.name} ${p.value}`.toLowerCase().includes(filter)) : pairs
	if(!rows.length)return null

	return (
		<Box component="section" sx={{bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '6px', overflow: 'hidden'}}>
			<Typography component="h2" sx={{display: 'flex', alignItems: 'center', height: 38, px: 1.75, borderBottom: `1px solid ${BORDER}`, fontSize: 12.5, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: '#3a3f55'}}>{title}</Typography>
			<Box component="table" sx={{width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed'}}>
				<tbody>
					{rows.map((p) => (
						<Box component="tr" key={p.name} sx={{'&:hover': {bgcolor: '#f8f9fb'}}}>
							<Box component="td" sx={{width: {xs: '40%', md: 300}, px: 1.75, py: 0.75, borderBottom: '1px solid #eef0f4', fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: 12.5, color: '#3a3f55', overflowWrap: 'anywhere', verticalAlign: 'top'}}>{p.name}</Box>
							<Box component="td" sx={{px: 1.75, py: 0.75, borderBottom: '1px solid #eef0f4', fontSize: 13, overflowWrap: 'anywhere', color: p.value === '********' ? '#9a9fb3' : (p.value === '' ? '#9a9fb3' : '#1f2335')}}>{p.value === '' ? '-' : p.value}</Box>
						</Box>
					))}
				</tbody>
			</Box>
		</Box>
	)

}

/**
 * module system-info: php, server and database information, secrets masked by the api
 */
export default function SystemInfoPage({icon}: {icon: string})
{

	const [data, setData] = useState<SystemInfo | null>(null)
	const [error, setError] = useState('')
	const [tab, setTab] = useState('server')
	const [search, setSearch] = useState('')

	useEffect(() => {

		api<SystemInfo>('system-info/').then(setData).catch((e) => setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))

	}, [])

	if(!data)return <Box sx={{display: 'flex', justifyContent: 'center', py: 8}}>{error ? <Typography color="error">{error}</Typography> : <CircularProgress />}</Box>

	const filter = search.trim().toLowerCase()
	const extensions = filter ? data.php.extensions.filter((e) => e.toLowerCase().includes(filter)) : data.php.extensions
	const stat = (label: string, value: string) => (
		<Box key={label} sx={{flex: '1 1 160px', px: 1.75, py: 1.5, bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '6px'}}>
			<Typography sx={{fontSize: 12, color: '#6b7088'}}>{label}</Typography>
			<Typography sx={{mt: 0.5, fontSize: 18, fontWeight: 600, overflowWrap: 'anywhere'}}>{value}</Typography>
		</Box>
	)

	return (
		<>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25}}>
				<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 2, bgcolor: '#f0ecff', color: 'primary.main'}}><MenuIcon name={icon} /></Box>
				<Typography component="h1" sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em'}}>{data.title}</Typography>
				<BookmarkButton module="system-info" />
			</Box>

			<Tabs value={tab} onChange={(_, value) => setTab(value)} sx={{minHeight: 40, mt: -1, borderBottom: `1px solid ${BORDER}`, '& .MuiTab-root': {minHeight: 40, px: 1.5, textTransform: 'none', fontSize: 14, fontWeight: 500}}}>
				<Tab value="server" label={t('Server')} />
				<Tab value="php" label="PHP" />
				<Tab value="database" label={t('Database')} />
			</Tabs>

			{tab === 'server' && <Box sx={{display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 1.25}}>{data.server.stats.map((s) => stat(s.label, s.value))}</Box>}
			{tab === 'php' && <Box sx={{display: 'flex', flexWrap: 'wrap', gap: 1.25}}>{data.php.stats.map((s) => stat(s.label, s.value))}</Box>}
			{tab === 'database' && <Box sx={{display: 'flex', flexWrap: 'wrap', gap: 1.25}}>{stat(t('Version'), data.database.version)}</Box>}

			<Box sx={{display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1.5}}>
				<Box component="label" sx={{width: {xs: '100%', sm: 300}, height: 38, display: 'flex', alignItems: 'center', gap: 1, px: 1.5, border: '1px solid #d5d8e3', borderRadius: 2, bgcolor: '#fff', color: '#6b7088'}}>
					<SearchOutlined sx={{fontSize: 18}} />
					<InputBase type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Filter: name or value...')} inputProps={{'aria-label': t('Filter')}} sx={{flexGrow: 1, fontSize: 14}} />
				</Box>
				<Typography sx={{fontSize: 13, color: '#6b7088'}}>{t('Values whose name holds password, secret, token or key are masked.')}</Typography>
			</Box>

			{tab === 'php' && (
				<>
					<PairTable title={t('Settings (php.ini)')} pairs={data.php.ini} filter={filter} />
					{extensions.length > 0 && (
						<Box component="section" sx={{bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '6px', overflow: 'hidden'}}>
							<Typography component="h2" sx={{display: 'flex', alignItems: 'center', height: 38, px: 1.75, borderBottom: `1px solid ${BORDER}`, fontSize: 12.5, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: '#3a3f55'}}>{t('Extensions')}</Typography>
							<Box sx={{display: 'flex', flexWrap: 'wrap', gap: 0.75, p: 1.5}}>
								{extensions.map((e) => <Box component="span" key={e} sx={{px: 1, py: '1px', borderRadius: 2.5, bgcolor: '#eef0f5', fontSize: 12, color: '#3a3f55'}}>{e}</Box>)}
							</Box>
						</Box>
					)}
					<PairTable title={t('Environment')} pairs={data.php.env} filter={filter} />
				</>
			)}
			{tab === 'server' && <PairTable title={t('Variables')} pairs={data.server.variables} filter={filter} />}
			{tab === 'database' && <PairTable title={t('Variables')} pairs={data.database.variables} filter={filter} />}
		</>
	)

}
