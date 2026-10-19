import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import { Alert, Snackbar } from '@mui/material'

type Severity = 'success' | 'error' | 'info' | 'warning'
type Toast = (message: string, severity?: Severity) => void

const ToastContext = createContext<Toast>(() => {})

/**
 * short notification at the bottom of the screen, one at a time
 */
export function ToastProvider({children}: {children: ReactNode})
{

	const [toast, setToast] = useState<{message: string, severity: Severity, key: number} | null>(null)
	const show = useCallback<Toast>((message, severity = 'success') => setToast({message, severity, key: Date.now()}), [])

	return (
		<ToastContext.Provider value={show}>
			{children}
			<Snackbar key={toast?.key} open={!!toast} autoHideDuration={toast?.severity === 'error' ? 6000 : 3000} onClose={(_, reason) => reason !== 'clickaway' && setToast(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'center'}}>
				<Alert severity={toast?.severity ?? 'success'} variant="filled" onClose={() => setToast(null)} sx={{minWidth: 260}}>{toast?.message}</Alert>
			</Snackbar>
		</ToastContext.Provider>
	)

}

export function useToast(): Toast
{

	return useContext(ToastContext)

}
