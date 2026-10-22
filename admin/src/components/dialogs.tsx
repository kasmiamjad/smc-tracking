import { Check, Copy } from 'lucide-react'
import { useState } from 'react'
import { Button, ErrorNotice, Field, Modal, Textarea } from './ui'

interface ReasonDialogProps {
  open: boolean
  onClose: () => void
  title: string
  description?: string
  confirmLabel: string
  reasonRequired?: boolean
  pending?: boolean
  error?: unknown
  onConfirm: (reason: string) => void
}

/** Confirm a destructive action, optionally with a reason (reject, block). */
export function ReasonDialog(props: ReasonDialogProps) {
  // Mounted only while open, so the reason starts empty every time.
  return props.open ? <ReasonDialogBody {...props} /> : null
}

function ReasonDialogBody({ onClose, title, description, confirmLabel, reasonRequired, pending, error, onConfirm }: ReasonDialogProps) {
  const [reason, setReason] = useState('')
  const missing = reasonRequired && !reason.trim()

  return (
    <Modal
      open
      onClose={onClose}
      title={title}
      description={description}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="danger" loading={pending} disabled={missing} onClick={() => onConfirm(reason.trim())}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <ErrorNotice error={error} />
        <Field label={reasonRequired ? 'Reason' : 'Reason (optional)'} hint={reasonRequired ? 'The technician is shown this reason.' : undefined}>
          {(id) => <Textarea id={id} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} autoFocus />}
        </Field>
      </div>
    </Modal>
  )
}

/** Shown once after provisioning or a PIN reset; the PIN is not retrievable later. */
export function TemporaryPinDialog({ pin, name, onClose }: { pin: string | null; name: string; onClose: () => void }) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    if (!pin) return
    try {
      await navigator.clipboard.writeText(pin)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // clipboard blocked; the PIN is still on screen
    }
  }

  return (
    <Modal
      open={pin !== null}
      onClose={onClose}
      title="Temporary PIN"
      description={`Give this PIN to ${name}. They must choose a new PIN the first time they sign in.`}
      footer={<Button onClick={onClose}>Done</Button>}
    >
      <div className="flex items-center justify-between gap-4 rounded-lg bg-slate-50 px-4 py-4 ring-1 ring-inset ring-slate-200">
        <span className="font-mono text-3xl font-semibold tracking-[0.4em] text-slate-900">{pin}</span>
        <Button variant="secondary" size="sm" icon={copied ? <Check className="size-4" /> : <Copy className="size-4" />} onClick={copy}>
          {copied ? 'Copied' : 'Copy'}
        </Button>
      </div>
      <p className="mt-3 text-xs text-slate-500">This PIN won't be shown again. If it's lost, reset the PIN again.</p>
    </Modal>
  )
}
