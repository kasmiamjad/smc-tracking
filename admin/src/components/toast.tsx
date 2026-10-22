import clsx from 'clsx'
import { CheckCircle2, XCircle } from 'lucide-react'
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'

interface Toast {
  id: number
  kind: 'success' | 'error'
  message: string
}

const ToastContext = createContext<(kind: Toast['kind'], message: string) => void>(() => {})

let nextId = 1

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])

  const push = useCallback((kind: Toast['kind'], message: string) => {
    const id = nextId++
    setToasts((all) => [...all, { id, kind, message }])
    setTimeout(() => setToasts((all) => all.filter((t) => t.id !== id)), 4000)
  }, [])

  return (
    <ToastContext.Provider value={push}>
      {children}
      <div className="pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex flex-col items-end gap-2 sm:left-auto sm:w-96" aria-live="polite">
        {toasts.map((t) => (
          <div
            key={t.id}
            className={clsx(
              'pointer-events-auto flex w-full items-start gap-2 rounded-lg px-4 py-3 text-sm shadow-lg ring-1',
              t.kind === 'success' ? 'bg-white text-slate-800 ring-emerald-200' : 'bg-white text-slate-800 ring-red-200',
            )}
          >
            {t.kind === 'success' ? <CheckCircle2 className="size-5 shrink-0 text-emerald-600" /> : <XCircle className="size-5 shrink-0 text-red-600" />}
            <span>{t.message}</span>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const push = useContext(ToastContext)
  return {
    success: (message: string) => push('success', message),
    error: (error: unknown) => push('error', error instanceof Error ? error.message : String(error)),
  }
}
