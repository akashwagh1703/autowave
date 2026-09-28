import { useEffect, useRef, useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import SendIcon from '@mui/icons-material/Send';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useAi from '@/hooks/useAi';
import { errorMessage, postJson } from '@/utils/http';

function CopyButton({ text }) {
    const [copied, setCopied] = useState(false);

    return (
        <Tooltip title={copied ? 'Copied' : 'Copy'}>
            <IconButton
                size="small"
                aria-label="Copy"
                onClick={() => {
                    navigator.clipboard?.writeText(text).then(() => {
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    });
                }}
            >
                <ContentCopyIcon fontSize="inherit" />
            </IconButton>
        </Tooltip>
    );
}

function Ask({ examples, limits, available }) {
    const [messages, setMessages] = useState([]);
    const [question, setQuestion] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const scroller = useRef(null);

    useEffect(() => {
        if (scroller.current) {
            scroller.current.scrollTop = scroller.current.scrollHeight;
        }
    }, [messages.length, loading]);

    const ask = async (text) => {
        const content = text.trim();

        if (!content || loading) {
            return;
        }

        const history = [...messages, { role: 'user', content }];
        setMessages(history);
        setQuestion('');
        setLoading(true);
        setError(null);

        try {
            const data = await postJson('/assistant/ask', {
                messages: history.slice(-limits.turns).map(({ role, content: body }) => ({ role, content: body })),
            });
            setMessages([...history, { role: 'assistant', content: data.reply }]);
        } catch (failure) {
            setError(errorMessage(failure, 'The assistant could not answer right now.'));
            setMessages(messages);
            setQuestion(content);
        } finally {
            setLoading(false);
        }
    };

    return (
        <Card variant="outlined">
            <CardContent className="flex min-h-[28rem] flex-col gap-3">
                <div ref={scroller} className="max-h-[32rem] min-h-0 flex-1 space-y-3 overflow-y-auto" aria-live="polite">
                    {messages.length === 0 ? (
                        <div className="py-6 text-center">
                            <AutoAwesomeIcon className="text-brand-600" />
                            <p className="mt-2 text-sm text-slate-600">Ask about your appointments, leads, orders and more. Answers use only data you can see.</p>
                            <div className="mt-4 flex flex-wrap justify-center gap-2">
                                {examples.map((example) => (
                                    <Chip key={example} label={example} onClick={() => ask(example)} disabled={!available || loading} variant="outlined" />
                                ))}
                            </div>
                        </div>
                    ) : null}
                    {messages.map((message, index) => (
                        <div key={index} className={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                            <div
                                className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-line ${
                                    message.role === 'user' ? 'rounded-br-sm bg-brand-600 text-white' : 'rounded-bl-sm border border-slate-200 bg-slate-50 text-slate-900'
                                }`}
                            >
                                {message.content}
                                {message.role === 'assistant' ? (
                                    <div className="mt-1 flex justify-end">
                                        <CopyButton text={message.content} />
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    ))}
                    {loading ? <p className="text-sm text-slate-500">Looking into it…</p> : null}
                </div>

                {error ? (
                    <Alert severity="error" onClose={() => setError(null)}>
                        {error}
                    </Alert>
                ) : null}

                <form
                    className="flex items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        ask(question);
                    }}
                >
                    <TextField
                        fullWidth
                        multiline
                        maxRows={4}
                        size="small"
                        placeholder="Ask a question… (Enter to send)"
                        value={question}
                        disabled={!available}
                        onChange={(event) => setQuestion(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
                                event.preventDefault();
                                ask(question);
                            }
                        }}
                        slotProps={{ htmlInput: { maxLength: limits.question, 'aria-label': 'Question' } }}
                    />
                    <Button type="submit" variant="contained" endIcon={<SendIcon />} disabled={!available || loading || !question.trim()}>
                        Ask
                    </Button>
                </form>
                <div className="flex items-center justify-between gap-2 text-xs text-slate-500">
                    <span>AI can make mistakes; check important numbers in the app. The chat is not saved.</span>
                    {messages.length ? (
                        <Button size="small" color="inherit" onClick={() => setMessages([])} disabled={loading}>
                            New chat
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

function Write({ copyKinds, limits, available }) {
    const [kind, setKind] = useState(copyKinds[0]?.key ?? '');
    const [instructions, setInstructions] = useState('');
    const [text, setText] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const selected = copyKinds.find((item) => item.key === kind);

    const generate = async (event) => {
        event.preventDefault();
        setLoading(true);
        setError(null);

        try {
            const data = await postJson('/ai/write', { kind, instructions: instructions.trim() || null });
            setText(data.text ?? '');
        } catch (failure) {
            setError(errorMessage(failure, 'AI could not write this right now.'));
        } finally {
            setLoading(false);
        }
    };

    return (
        <Card variant="outlined">
            <CardContent>
                <form onSubmit={generate} className="space-y-3">
                    <TextField select label="What do you need?" fullWidth value={kind} onChange={(event) => setKind(event.target.value)} helperText={selected?.description}>
                        {copyKinds.map((item) => (
                            <MenuItem key={item.key} value={item.key}>
                                {item.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField
                        label="Details (optional)"
                        fullWidth
                        multiline
                        minRows={3}
                        value={instructions}
                        onChange={(event) => setInstructions(event.target.value)}
                        placeholder="e.g. 20% off haircuts this weekend only, mention booking on WhatsApp"
                        slotProps={{ htmlInput: { maxLength: limits.instructions } }}
                    />
                    <div className="flex justify-end">
                        <Button type="submit" variant="contained" startIcon={<AutoAwesomeIcon />} disabled={!available || loading || !kind}>
                            {loading ? 'Writing…' : text ? 'Write again' : 'Write it'}
                        </Button>
                    </div>
                </form>
                {error ? (
                    <Alert severity="error" className="mt-3">
                        {error}
                    </Alert>
                ) : null}
                {text ? (
                    <div className="mt-4">
                        <TextField
                            label="Your text (edit freely)"
                            fullWidth
                            multiline
                            minRows={5}
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            helperText="Copy it into WhatsApp, Instagram or wherever you need it. Check prices and dates first."
                        />
                        <div className="mt-2 flex justify-end">
                            <CopyButton text={text} />
                        </div>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

export default function Index({ canAsk, examples, copyKinds, limits }) {
    const ai = useAi();
    const [tab, setTab] = useState(canAsk ? 'ask' : 'write');

    return (
        <AppLayout title="Assistant">
            <PageHeader title="Assistant" description="Ask about your business or get help writing messages. Nothing is sent to customers from here." />

            {!ai.available && ai.message ? (
                <Alert severity={ai.reason === 'limit' ? 'warning' : 'info'} className="mb-4">
                    {ai.message}
                </Alert>
            ) : null}

            <div className="max-w-3xl">
                <Tabs value={tab} onChange={(event, value) => setTab(value)} className="mb-4">
                    {canAsk ? <Tab value="ask" label="Ask" /> : null}
                    <Tab value="write" label="Write" />
                </Tabs>
                {tab === 'ask' && canAsk ? <Ask examples={examples} limits={limits} available={ai.available} /> : null}
                {tab === 'write' ? <Write copyKinds={copyKinds} limits={limits} available={ai.available} /> : null}
            </div>
        </AppLayout>
    );
}
