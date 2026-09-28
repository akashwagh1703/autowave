import { Link, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import CustomerPicker from '@/modules/booking/CustomerPicker';
import InstalmentsEditor from '@/modules/education/InstalmentsEditor';
import useTenant from '@/hooks/useTenant';
import { formatDay } from '@/utils/booking';
import { netFee, splitFee } from '@/utils/education';
import { formatPrice } from '@/utils/format';

export default function Create({ batches, lead, customer, defaultBatchId, defaultInstalments, maxInstalments, today, paymentMethods }) {
    const { currency } = useTenant();
    const initialBatch = batches.find((batch) => batch.id === defaultBatchId) ?? batches[0] ?? null;
    const [mode, setMode] = useState(lead ? 'lead' : 'existing');
    const [picked, setPicked] = useState(customer);
    const [planMode, setPlanMode] = useState('split');

    const form = useForm({
        batch_id: initialBatch?.id ?? '',
        enrolled_on: today,
        fee_total: initialBatch?.effective_fee ?? '',
        discount: '',
        instalment_count: defaultInstalments,
        first_due_on: today,
        instalments: [],
        customer: { name: '', phone: '', email: '' },
        notes: '',
        payment: { amount: '', method: paymentMethods[0]?.value ?? 'cash', reference: '' },
    });

    const batch = batches.find((item) => item.id === Number(form.data.batch_id));
    const net = netFee(form.data.fee_total, form.data.discount);
    const preview = splitFee(net, form.data.instalment_count, form.data.first_due_on);

    const chooseBatch = (id) => {
        const next = batches.find((item) => item.id === Number(id));
        form.setData((data) => ({ ...data, batch_id: id, fee_total: next?.effective_fee ?? data.fee_total }));
    };

    const switchPlan = (value) => {
        if (!value) {
            return;
        }

        if (value === 'custom' && form.data.instalments.length === 0) {
            form.setData('instalments', preview.length ? preview : [{ due_on: form.data.first_due_on, amount: net }]);
        }

        setPlanMode(value);
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            batch_id: data.batch_id,
            enrolled_on: data.enrolled_on,
            fee_total: data.fee_total,
            discount: data.discount,
            notes: data.notes,
            lead_id: mode === 'lead' ? lead?.id : null,
            customer_id: mode === 'existing' ? picked?.id ?? null : null,
            customer: mode === 'new' ? data.customer : null,
            ...(planMode === 'custom' ? { instalments: data.instalments } : { instalment_count: data.instalment_count, first_due_on: data.first_due_on }),
            payment: data.payment.amount ? data.payment : null,
        }));
        form.post('/students');
    };

    const setCustomer = (field, value) => form.setData('customer', { ...form.data.customer, [field]: value });
    const setPayment = (field, value) => form.setData('payment', { ...form.data.payment, [field]: value });

    return (
        <AppLayout title="Admit student">
            <Button component={Link} href={lead ? `/leads/${lead.id}` : '/students'} startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                {lead ? lead.name : 'Students'}
            </Button>
            <PageHeader title="Admit student" description="Adds the student to a batch with a fee plan. Enquiries are marked as admitted." />

            {batches.length === 0 ? (
                <Alert severity="info">
                    No batches are open for admissions.{' '}
                    <Link href="/courses" className="underline">
                        Set up courses and batches
                    </Link>
                </Alert>
            ) : (
                <form onSubmit={submit} noValidate className="max-w-4xl space-y-6">
                    <Card variant="outlined">
                        <CardContent className="space-y-4">
                            <h2 className="font-semibold text-slate-900">Student</h2>
                            {lead ? (
                                <div className="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm">
                                    <p className="font-medium text-slate-900">{lead.name}</p>
                                    <p className="text-slate-600">{[lead.phone, lead.email, `Enquiry · ${lead.stage}`].filter(Boolean).join(' · ')}</p>
                                </div>
                            ) : (
                                <>
                                    <Tabs value={mode} onChange={(_, value) => setMode(value)}>
                                        <Tab value="existing" label="Existing customer" />
                                        <Tab value="new" label="New student" />
                                    </Tabs>
                                    {mode === 'existing' ? (
                                        <CustomerPicker value={picked} onChange={setPicked} endpoint="/students/lookup" label="Student" error={form.errors.customer_id} autoFocus />
                                    ) : (
                                        <div className="grid gap-4 sm:grid-cols-3">
                                            <TextField
                                                label="Name"
                                                required
                                                value={form.data.customer.name}
                                                onChange={(event) => setCustomer('name', event.target.value)}
                                                error={Boolean(form.errors['customer.name'] || form.errors.customer_id)}
                                                helperText={form.errors['customer.name'] ?? form.errors.customer_id}
                                                slotProps={{ htmlInput: { maxLength: 120 } }}
                                            />
                                            <TextField
                                                label="Phone"
                                                value={form.data.customer.phone}
                                                onChange={(event) => setCustomer('phone', event.target.value)}
                                                error={Boolean(form.errors['customer.phone'])}
                                                helperText={form.errors['customer.phone'] ?? 'An existing customer with this phone is reused.'}
                                                slotProps={{ htmlInput: { maxLength: 30 } }}
                                            />
                                            <TextField
                                                label="Email"
                                                type="email"
                                                value={form.data.customer.email}
                                                onChange={(event) => setCustomer('email', event.target.value)}
                                                error={Boolean(form.errors['customer.email'])}
                                                helperText={form.errors['customer.email']}
                                                slotProps={{ htmlInput: { maxLength: 190 } }}
                                            />
                                        </div>
                                    )}
                                </>
                            )}
                            {form.errors.lead_id ? <Alert severity="error">{form.errors.lead_id}</Alert> : null}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <h2 className="font-semibold text-slate-900 sm:col-span-2">Batch</h2>
                            <TextField
                                select
                                label="Batch"
                                required
                                value={form.data.batch_id}
                                onChange={(event) => chooseBatch(event.target.value)}
                                error={Boolean(form.errors.batch_id)}
                                helperText={form.errors.batch_id ?? batch?.schedule ?? ''}
                            >
                                {batches.map((item) => (
                                    <MenuItem key={item.id} value={item.id} disabled={item.seats_left === 0}>
                                        {item.label}
                                        <span className="ml-2 text-xs text-slate-500">
                                            {item.seats_left === 0 ? 'Full' : item.seats_left !== null ? `${item.seats_left} seats left` : ''}
                                        </span>
                                    </MenuItem>
                                ))}
                            </TextField>
                            <TextField
                                label="Admission date"
                                type="date"
                                value={form.data.enrolled_on}
                                onChange={(event) => form.setData('enrolled_on', event.target.value)}
                                error={Boolean(form.errors.enrolled_on)}
                                helperText={form.errors.enrolled_on}
                                slotProps={{ inputLabel: { shrink: true } }}
                            />
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent className="space-y-4">
                            <h2 className="font-semibold text-slate-900">Fees</h2>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <TextField
                                    label="Fee"
                                    type="number"
                                    value={form.data.fee_total}
                                    onChange={(event) => form.setData('fee_total', event.target.value)}
                                    error={Boolean(form.errors.fee_total)}
                                    helperText={form.errors.fee_total ?? (batch ? `Batch fee ${formatPrice(batch.effective_fee, currency)}` : '')}
                                    slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                                />
                                <TextField
                                    label="Discount"
                                    type="number"
                                    value={form.data.discount}
                                    onChange={(event) => form.setData('discount', event.target.value)}
                                    error={Boolean(form.errors.discount)}
                                    helperText={form.errors.discount}
                                    slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                                />
                                <div className="flex flex-col justify-center">
                                    <p className="text-sm text-slate-500">Payable</p>
                                    <p className="text-xl font-semibold text-slate-900">{formatPrice(net, currency)}</p>
                                </div>
                            </div>

                            {Number(net) > 0 ? (
                                <>
                                    <ToggleButtonGroup exclusive size="small" value={planMode} onChange={(_, value) => switchPlan(value)} aria-label="Instalment plan">
                                        <ToggleButton value="split">Equal monthly instalments</ToggleButton>
                                        <ToggleButton value="custom">Custom plan</ToggleButton>
                                    </ToggleButtonGroup>
                                    {planMode === 'split' ? (
                                        <div className="space-y-3">
                                            <div className="grid gap-4 sm:grid-cols-3">
                                                <TextField
                                                    label="Instalments"
                                                    type="number"
                                                    value={form.data.instalment_count}
                                                    onChange={(event) => form.setData('instalment_count', event.target.value)}
                                                    error={Boolean(form.errors.instalment_count)}
                                                    helperText={form.errors.instalment_count}
                                                    slotProps={{ htmlInput: { min: 1, max: maxInstalments } }}
                                                />
                                                <TextField
                                                    label="First due on"
                                                    type="date"
                                                    value={form.data.first_due_on}
                                                    onChange={(event) => form.setData('first_due_on', event.target.value)}
                                                    error={Boolean(form.errors.first_due_on)}
                                                    helperText={form.errors.first_due_on}
                                                    slotProps={{ inputLabel: { shrink: true } }}
                                                />
                                            </div>
                                            {preview.length > 0 ? (
                                                <ul className="flex flex-wrap gap-2 text-sm">
                                                    {preview.map((row, index) => (
                                                        <li key={index} className="rounded-md bg-slate-100 px-2 py-1 text-slate-700">
                                                            {formatDay(row.due_on, { day: 'numeric', month: 'short', year: 'numeric' })} · {formatPrice(row.amount, currency)}
                                                        </li>
                                                    ))}
                                                </ul>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <InstalmentsEditor rows={form.data.instalments} onChange={(rows) => form.setData('instalments', rows)} net={net} errors={form.errors} max={maxInstalments} />
                                    )}
                                </>
                            ) : null}
                        </CardContent>
                    </Card>

                    {Number(net) > 0 ? (
                        <Card variant="outlined">
                            <CardContent className="space-y-4">
                                <div>
                                    <h2 className="font-semibold text-slate-900">Payment received now</h2>
                                    <p className="text-sm text-slate-600">Optional. Applied to the earliest instalments.</p>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <TextField
                                        label="Amount"
                                        type="number"
                                        value={form.data.payment.amount}
                                        onChange={(event) => setPayment('amount', event.target.value)}
                                        error={Boolean(form.errors['payment.amount'] || form.errors.amount)}
                                        helperText={form.errors['payment.amount'] ?? form.errors.amount}
                                        slotProps={{ htmlInput: { min: 0, max: net, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                                    />
                                    <TextField
                                        select
                                        label="Method"
                                        value={form.data.payment.method}
                                        onChange={(event) => setPayment('method', event.target.value)}
                                        error={Boolean(form.errors['payment.method'])}
                                        helperText={form.errors['payment.method']}
                                    >
                                        {paymentMethods.map((method) => (
                                            <MenuItem key={method.value} value={method.value}>
                                                {method.label}
                                            </MenuItem>
                                        ))}
                                    </TextField>
                                    <TextField
                                        label="Reference"
                                        value={form.data.payment.reference}
                                        onChange={(event) => setPayment('reference', event.target.value)}
                                        error={Boolean(form.errors['payment.reference'])}
                                        helperText={form.errors['payment.reference'] ?? 'e.g. UPI transaction ID'}
                                        slotProps={{ htmlInput: { maxLength: 100 } }}
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card variant="outlined">
                        <CardContent>
                            <TextField
                                label="Notes"
                                fullWidth
                                multiline
                                minRows={2}
                                value={form.data.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                                error={Boolean(form.errors.notes)}
                                helperText={form.errors.notes ?? 'Internal. e.g. school, parent’s name.'}
                                slotProps={{ htmlInput: { maxLength: 2000 } }}
                            />
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button component={Link} href={lead ? `/leads/${lead.id}` : '/students'} color="inherit">
                            Cancel
                        </Button>
                        <Button type="submit" variant="contained" disabled={form.processing}>
                            Admit student
                        </Button>
                    </div>
                </form>
            )}
        </AppLayout>
    );
}
