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

// routes end with a slash (rule), basename = the url of the backend
const router = createBrowserRouter([
	{path: '/', element: <RequireAuth><Dashboard /></RequireAuth>},
	{path: '/login/', element: <Login />},
	{path: '/password/forgot/', element: <ForgotPassword />},
	{path: '/password/reset/', element: <ResetPassword />},
	{path: '*', element: <Navigate to="/" replace />},
], {basename: '/backend/'})

export default function App()
{

	return (
		<ThemeProvider theme={theme}>
			<CssBaseline />
			<AuthProvider>
				<RouterProvider router={router} />
			</AuthProvider>
		</ThemeProvider>
	)

}
