import { Download, PlusSquare, Share, X } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useInstall } from '../lib/install'
import { Button } from './ui'

export function Sheet({ open, onClose, title, children }: { open: boolean; onClose: () => void; title: string; children: ReactNode }) {
  if (!open) return null
  // portal: animated ancestors (transform) would otherwise trap position:fixed
  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40" onClick={onClose} role="dialog" aria-modal aria-label={title}>
      <div className="anim-rise w-full max-w-md rounded-t-[28px] bg-surface p-5 safe-bottom" onClick={(e) => e.stopPropagation()}>
        <div className="mx-auto mb-3 h-1.5 w-10 rounded-full bg-line" />
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-bold">{title}</h2>
          <button aria-label="Close" onClick={onClose} className="grid size-9 place-items-center rounded-full bg-surface-2">
            <X className="size-5" />
          </button>
        </div>
        {children}
      </div>
    </div>,
    document.body,
  )
}

/** "Get the app": one tap on Android, a short how-to on iPhone. Hidden once installed. */
export function InstallCard({ compact, icon }: { compact?: boolean; icon?: ReactNode }) {
  const inst = useInstall()
  const [sheet, setSheet] = useState(false)
  if (inst.installed) return null
  const go = async () => {
    if (inst.canPrompt) await inst.prompt()
    else setSheet(true)
  }
  return (
    <>
      <div className="flex items-center gap-3 rounded-3xl border border-line bg-surface p-4">
        <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-up-soft text-up">{icon ?? <Download className="size-5" />}</span>
        <span className="min-w-0 flex-1">
          <span className="block font-semibold">Get the PipStream app</span>
          <span className="block text-sm text-ink-2">{compact ? 'Install it on your phone. No app store needed.' : 'Add it to your home screen for one-tap access and alerts.'}</span>
        </span>
        <Button variant="soft" className="min-h-10 px-4" onClick={go}>
          Install
        </Button>
      </div>
      <Sheet open={sheet} onClose={() => setSheet(false)} title="Add PipStream to your home screen">
        {inst.ios ? (
          <ol className="space-y-4 text-[15px]">
            <li className="flex items-center gap-3">
              <span className="grid size-10 place-items-center rounded-xl bg-surface-2"><Share className="size-5 text-brand" /></span>
              Tap <b>Share</b> at the bottom of Safari.
            </li>
            <li className="flex items-center gap-3">
              <span className="grid size-10 place-items-center rounded-xl bg-surface-2"><PlusSquare className="size-5 text-brand" /></span>
              Choose <b>Add to Home Screen</b>, then <b>Add</b>.
            </li>
          </ol>
        ) : (
          <ol className="space-y-3 text-[15px]">
            <li>1. Open your browser menu (⋮ at the top right).</li>
            <li>2. Tap <b>Install app</b> or <b>Add to Home screen</b>.</li>
          </ol>
        )}
        <p className="mt-4 text-sm text-ink-3">PipStream then opens full screen like any other app.</p>
      </Sheet>
    </>
  )
}
