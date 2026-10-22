import { CheckCircle2, Download, FileText, Trash2, Upload, XCircle } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { fetchBlobUrl } from '../lib/api'
import { useAuth } from '../lib/auth'
import { formatBytes, formatDateTime } from '../lib/format'
import { useDeleteDocument, useUploadDocument, useVerifyDocument } from '../lib/queries'
import { DOC_TYPE_LABELS, type DocType, type UserDocument } from '../lib/types'
import { ReasonDialog } from './dialogs'
import { Badge, Button, Select, Spinner } from './ui'
import { useToast } from './toast'

function ImageThumb({ doc }: { doc: UserDocument }) {
  const [src, setSrc] = useState<string | null>(null)

  useEffect(() => {
    let url: string | null = null
    let cancelled = false
    fetchBlobUrl(doc.downloadUrl)
      .then((u) => {
        url = u
        if (!cancelled) setSrc(u)
      })
      .catch(() => {})
    return () => {
      cancelled = true
      if (url) URL.revokeObjectURL(url)
    }
  }, [doc.downloadUrl])

  return (
    <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200">
      {src ? <img src={src} alt={doc.originalName} className="size-full object-cover" /> : <Spinner className="size-4" />}
    </div>
  )
}

function VerificationBadge({ doc }: { doc: UserDocument }) {
  if (doc.verificationStatus === 'APPROVED') return <Badge tone="green">Verified</Badge>
  if (doc.verificationStatus === 'REJECTED') return <Badge tone="red">Rejected</Badge>
  return <Badge tone="amber">Not verified</Badge>
}

export function DocumentList({ userId, documents, allowUpload = true }: { userId: string; documents: UserDocument[]; allowUpload?: boolean }) {
  const { isAdmin } = useAuth()
  const toast = useToast()
  const verify = useVerifyDocument()
  const remove = useDeleteDocument()
  const upload = useUploadDocument()
  const fileInput = useRef<HTMLInputElement>(null)
  const [docType, setDocType] = useState<DocType>('NATIONAL_ID')
  const [rejecting, setRejecting] = useState<UserDocument | null>(null)

  async function open(doc: UserDocument) {
    try {
      const url = await fetchBlobUrl(doc.downloadUrl)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (e) {
      toast.error(e)
    }
  }

  function onFile(file: File | undefined) {
    if (!file) return
    upload.mutate(
      { userId, file, docType },
      {
        onSuccess: () => toast.success('Document uploaded.'),
        onError: (e) => toast.error(e),
        onSettled: () => fileInput.current && (fileInput.current.value = ''),
      },
    )
  }

  return (
    <div className="space-y-3">
      {documents.length === 0 && <p className="text-sm text-slate-500">No documents uploaded. Documents are optional.</p>}

      {documents.map((doc) => (
        <div key={doc.id} className="flex items-center gap-3 rounded-lg p-2 ring-1 ring-slate-200">
          {doc.mimeType.startsWith('image/') ? (
            <ImageThumb doc={doc} />
          ) : (
            <div className="flex size-16 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-500 ring-1 ring-red-100">
              <FileText className="size-7" />
            </div>
          )}

          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-sm font-medium text-slate-900">{DOC_TYPE_LABELS[doc.docType]}</p>
              <VerificationBadge doc={doc} />
            </div>
            <p className="truncate text-xs text-slate-500">
              {doc.originalName} · {formatBytes(doc.sizeBytes)} · {formatDateTime(doc.uploadedAt)}
            </p>
            {doc.rejectionReason && <p className="mt-0.5 text-xs text-red-600">{doc.rejectionReason}</p>}
          </div>

          <div className="flex shrink-0 items-center gap-1">
            <Button variant="ghost" size="sm" icon={<Download className="size-4" />} onClick={() => open(doc)} title="Open">
              <span className="sr-only">Open</span>
            </Button>
            {isAdmin && doc.verificationStatus !== 'APPROVED' && (
              <Button
                variant="ghost"
                size="sm"
                title="Mark verified"
                icon={<CheckCircle2 className="size-4 text-emerald-600" />}
                onClick={() => verify.mutate({ id: doc.id, status: 'APPROVED' }, { onSuccess: () => toast.success('Document verified.'), onError: toast.error })}
              >
                <span className="sr-only">Verify</span>
              </Button>
            )}
            {isAdmin && doc.verificationStatus === 'PENDING' && (
              <Button variant="ghost" size="sm" title="Reject document" icon={<XCircle className="size-4 text-red-600" />} onClick={() => setRejecting(doc)}>
                <span className="sr-only">Reject</span>
              </Button>
            )}
            {isAdmin && (
              <Button
                variant="ghost"
                size="sm"
                title="Delete"
                icon={<Trash2 className="size-4 text-slate-400" />}
                onClick={() =>
                  confirm(`Delete ${doc.originalName}? This can't be undone.`) &&
                  remove.mutate(doc.id, { onSuccess: () => toast.success('Document deleted.'), onError: toast.error })
                }
              >
                <span className="sr-only">Delete</span>
              </Button>
            )}
          </div>
        </div>
      ))}

      {isAdmin && allowUpload && (
        <div className="flex flex-wrap items-center gap-2 pt-1">
          <Select value={docType} onChange={(e) => setDocType(e.target.value as DocType)} className="w-auto" aria-label="Document type">
            {Object.entries(DOC_TYPE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
          <input ref={fileInput} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" className="hidden" onChange={(e) => onFile(e.target.files?.[0])} />
          <Button variant="secondary" icon={<Upload className="size-4" />} loading={upload.isPending} onClick={() => fileInput.current?.click()}>
            Upload document
          </Button>
          <span className="text-xs text-slate-400">PDF or image, up to 10 MB</span>
        </div>
      )}

      <ReasonDialog
        open={rejecting !== null}
        onClose={() => setRejecting(null)}
        title="Reject document"
        description={rejecting ? `${DOC_TYPE_LABELS[rejecting.docType]} · ${rejecting.originalName}` : undefined}
        confirmLabel="Reject document"
        reasonRequired
        pending={verify.isPending}
        error={verify.error}
        onConfirm={(reason) =>
          rejecting &&
          verify.mutate(
            { id: rejecting.id, status: 'REJECTED', reason },
            {
              onSuccess: () => {
                setRejecting(null)
                toast.success('Document rejected.')
              },
            },
          )
        }
      />
    </div>
  )
}
