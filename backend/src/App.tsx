import { createBrowserRouter, Navigate } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import { Box, CircularProgress, CssBaseline, ThemeProvider, createTheme } from '@mui/material'
import { AuthProvider, useAuth } from './auth'
import Login from './pages/Login'
import Dashboard from './pages/Dashboard'

// primary color from the unee logo gradient
const theme = createTheme({
	palette: {
		primary: {main: '#6d4aff'},
	},
	shape: {borderRadius: 10},
	components: {
		MuiButton: {styleOverrides: {root: {textTransform: 'none', fontWeight: 600}}},
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
