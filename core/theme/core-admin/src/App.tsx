import { createBrowserRouter, Navigate } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import { Box, CircularProgress, CssBaseline, ThemeProvider, createTheme } from '@mui/material'
import { BASE } from './base'
// latin subsets only (french, english, european languages)
import '@fontsource/ibm-plex-sans/latin-400.css'
import '@fontsource/ibm-plex-sans/latin-ext-400.css'
import '@fontsource/ibm-plex-sans/latin-500.css'
import '@fontsource/ibm-plex-sans/latin-ext-500.css'
import '@fontsource/ibm-plex-sans/latin-600.css'
import '@fontsource/ibm-plex-sans/latin-ext-600.css'
import './colors.css'
import { AuthProvider, useAuth } from './auth'
import Login from './pages/Login'
import ForgotPassword from './pages/ForgotPassword'
import ResetPassword from './pages/ResetPassword'
import Dashboard from './pages/Dashboard'
import ModulePage from './pages/ModulePage'
import NotFound from './pages/NotFound'
import BackendLayout from './components/BackendLayout'
import { ToastProvider } from './components/Toast'

// corporate theme: primary color from the unee logo gradient, compact controls (40px), ibm plex sans
// light and dark schemes (class on <html>, mode kept by mui in localStorage), the other colors are the --u-* variables of colors.css
const theme = createTheme({
	cssVariables: {colorSchemeSelector: 'class'},
	colorSchemes: {
		light: {palette: {primary: {main: '#6d4aff'}, text: {primary: '#1f2335', secondary: '#5b6178'}, background: {default: '#f7f8fb', paper: '#fff'}}},
		dark: {palette: {primary: {main: '#8b6dff'}, text: {primary: '#e7e9f1', secondary: '#a4a9bd'}, background: {default: '#12141c', paper: '#1b1e29'}, divider: '#2a2e3c'}},
	},
	typography: {
		fontFamily: '"IBM Plex Sans", system-ui, sans-serif',
		fontSize: 14,
	},
	shape: {borderRadius: 8},
	components: {
		MuiButton: {
			defaultProps: {disableElevation: true},
			styleOverrides: {root: {textTransform: 'none', fontWeight: 600, height: 40}},
		},
		MuiOutlinedInput: {
			styleOverrides: {
				root: {height: 40, fontSize: 14},
				notchedOutline: {borderColor: 'var(--u-border-strong)'},
			},
		},
		// no light overlay on the papers in dark mode: the dialogs keep the color of the cards
		MuiPaper: {styleOverrides: {root: {backgroundImage: 'none'}}},
		MuiAlert: {styleOverrides: {root: {fontSize: 13, borderRadius: 8}}},
		// dialogs at the top of the screen, not centered (full screen dialogs on mobile unchanged)
		MuiDialog: {
			styleOverrides: {
				container: {alignItems: 'flex-start'},
				paper: {'&:not(.MuiDialog-paperFullScreen)': {marginTop: 48}},
			},
		},
	},
})

// redirects to the login page when nobody is logged
function RequireAuth({children}: {children: React.ReactNode})
{

	const {user, loading} = useAuth()
	if(loading)return <Box sx={{display: 'flex', justifyContent: 'center', mt: 10}}><CircularProgress /></Box>
	if(!user)return <Navigate to="/login/" replace />
	return children

}

// routes end with a slash (rule), basename = the url of the backend, a module page = /<module>/ (folder of the module)
const router = createBrowserRouter([
	{path: '/login/', element: <Login />},
	{path: '/password/forgot/', element: <ForgotPassword />},
	{path: '/password/reset/', element: <ResetPassword />},
	{
		element: <RequireAuth><BackendLayout /></RequireAuth>,
		children: [
			{path: '/', element: <Dashboard />},
			{path: '/:module/', element: <ModulePage />},
			{path: '*', element: <NotFound />},
		],
	},
], {basename: BASE})

// remounted when the language changes: every page renders again with the new translations
function Root()
{

	const {user} = useAuth()
	return <RouterProvider key={user?.language ?? ''} router={router} />

}

export default function App()
{

	return (
		<ThemeProvider theme={theme} defaultMode="light">
			<CssBaseline />
			<ToastProvider>
				<AuthProvider>
					<Root />
				</AuthProvider>
			</ToastProvider>
		</ThemeProvider>
	)

}
