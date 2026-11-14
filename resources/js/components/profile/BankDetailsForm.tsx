import { useForm } from '@inertiajs/react';
import { Loader2, Landmark } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { FieldError, Input, Label } from '@/components/ui/input';

export interface BankData {
    bank_holder: string;
    bank_tax_id: string;
    bank_name: string;
    bank_cbu: string;
    bank_alias: string;
}

/** Datos para que los clientes transfieran. Se usan en los correos de cobro de los servicios que esta persona tiene a cargo. */
export function BankDetailsForm({ action, initial, idPrefix, onSaved, footer }: { action: string; initial: BankData; idPrefix: string; onSaved?: () => void; footer?: (state: { processing: boolean; dirty: boolean }) => React.ReactNode }) {
    const form = useForm<BankData>(initial);
    const set = (k: keyof BankData) => (e: React.ChangeEvent<HTMLInputElement>) => form.setData(k, e.target.value);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(action, { preserveScroll: true, onSuccess: () => { form.setDefaults(); onSaved?.(); } });
    };

    return (
        <form id={`${idPrefix}-form`} onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${idPrefix}-holder`}>Titular de la cuenta</Label>
                <Input id={`${idPrefix}-holder`} autoComplete="off" value={form.data.bank_holder} onChange={set('bank_holder')} />
                <FieldError message={form.errors.bank_holder} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${idPrefix}-tax`}>CUIT / CUIL</Label>
                <Input id={`${idPrefix}-tax`} inputMode="numeric" placeholder="20-12345678-9" autoComplete="off" value={form.data.bank_tax_id} onChange={set('bank_tax_id')} />
                <FieldError message={form.errors.bank_tax_id} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${idPrefix}-bank`}>Banco o billetera</Label>
                <Input id={`${idPrefix}-bank`} placeholder="Ej.: Mercado Pago, Galicia…" autoComplete="off" value={form.data.bank_name} onChange={set('bank_name')} />
                <FieldError message={form.errors.bank_name} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${idPrefix}-alias`}>Alias</Label>
                <Input id={`${idPrefix}-alias`} autoComplete="off" value={form.data.bank_alias} onChange={set('bank_alias')} />
                <FieldError message={form.errors.bank_alias} />
            </div>
            <div className="flex flex-col gap-1.5 sm:col-span-2">
                <Label htmlFor={`${idPrefix}-cbu`}>CBU / CVU</Label>
                <Input id={`${idPrefix}-cbu`} inputMode="numeric" placeholder="22 números" autoComplete="off" value={form.data.bank_cbu} onChange={set('bank_cbu')} />
                <FieldError message={form.errors.bank_cbu} />
            </div>
            {footer ? footer({ processing: form.processing, dirty: form.isDirty }) : (
                <div className="flex justify-end sm:col-span-2">
                    <Button type="submit" disabled={form.processing || !form.isDirty}>{form.processing ? <Loader2 className="animate-spin" /> : <Landmark />} Guardar datos bancarios</Button>
                </div>
            )}
        </form>
    );
}
