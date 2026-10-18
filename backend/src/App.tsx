import { createBrowserRouter, Navigate } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import { Box, CircularProgress, CssBaseline, ThemeProvider, createTheme } from '@mui/material'
// latin subsets only (french, english, european languages)
import '@fontsource/ibm-plex-sans/latin-400.css'
import '@fontsource/ibm-plex-sans/latin-ext-400.css'
import '@fontsource/ibm-plex-sans/latin-500.css'
import '@fontsource/ibm-plex-sans/latin-ext-500.css'
import '@fontsource/ibm-plex-sans/latin-600.css'
import '@fontsource/ibm-plex-sans/latin-ext-600.css'
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
const theme = createTheme({
	palette: {
		primary: {main: '#6d4aff'},
		text: {primary: '#1f2335', secondary: '#5b6178'},
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
				notchedOutline: {borderColor: '#d5d8e3'},
			},
		},
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
], {basename: '/backend/'})

// remounted when the language changes: every page renders again with the new translations
function Root()
{

	const {user} = useAuth()
	return <RouterProvider key={user?.language ?? ''} router={router} />

}

export default function App()
{

	return (
		<ThemeProvider theme={theme}>
			<CssBaseline />
			<ToastProvider>
				<AuthProvider>
					<Root />
				</AuthProvider>
			</ToastProvider>
		</ThemeProvider>
	)

}
