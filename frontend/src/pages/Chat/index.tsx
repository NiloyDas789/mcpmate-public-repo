import {
  useEffect,
  useRef,
  useState,
  type ChangeEvent,
  type FormEvent,
  type KeyboardEvent,
} from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useLocation, useNavigate } from "react-router";
import { __, sprintf } from "@wordpress/i18n";
import PageHeader from "@components/AppShell/PageHeader";
import Badge from "@components/ui/Badge";
import Button from "@components/ui/Button";
import Card from "@components/ui/Card";
import ConfirmDialog from "@components/ui/ConfirmDialog";
import EmptyState from "@components/ui/EmptyState";
import JsonBlock from "@components/ui/JsonBlock";
import Notice from "@components/ui/Notice";
import Skeleton from "@components/ui/Skeleton";
import { useAnnounce } from "@/hooks/useAnnounce";
import {
  acceptChat,
  chatStream,
  deleteChatSession,
  fetchChatSession,
  fetchChatSessions,
} from "@services/api";
import {
  CHAT_QUERY_KEY,
  CHAT_SESSIONS_QUERY_KEY,
  useChatSettings,
} from "@services/queries";
import { cx } from "@utils/classNames";
import { requestErrorText } from "@utils/errors";
import type {
  ChatItem,
  ChatPlanCall,
  ChatSession,
  ChatSettingsView,
} from "../../types/api";
import {
  applyEvent,
  bytesText,
  EMPTY_LIVE,
  fileToBase64,
  imageProblem,
  kindLabel,
  planTitle,
  SESSION_PARAM,
  sessionFromSearch,
  statusLabel,
  type Live,
} from "./logic";

const sessionKey = (id: number | null) => ["chat-session", id];

interface StepError {
  code: string;
  message: string;
  hint?: string;
  /** The conversation the failed step was for; null for a new chat that never started. */
  sessionId: number | null;
  /** The admin's message was saved before the step failed. */
  kept?: boolean;
}

interface StepResult {
  session: ChatSession | null;
  error: StepError | null;
}

// ── First use ───────────────────────────────────

/** The cost notice; accepting it connects this admin's chat grant. */
function ConsentCard({ settings }: { settings: ChatSettingsView }) {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const revoked = settings.consent && !settings.connected;

  const start = async () => {
    setBusy(true);
    setError("");
    try {
      queryClient.setQueryData(CHAT_QUERY_KEY, await acceptChat());
    } catch (caught) {
      setError(requestErrorText(caught));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card
      title={
        revoked
          ? __("Chat's access to this site was revoked", "mcpmate")
          : __("Before you start", "mcpmate")
      }
    >
      <div className="el-stack">
        {revoked ? (
          <p className="m-0">
            {__(
              "Someone revoked Chat's grant on Connections, so it can no longer use the site's tools. Connect it again to give it a new grant with Full access; you can change that on Connections.",
              "mcpmate",
            )}
          </p>
        ) : (
          <>
            <p className="m-0">
              {__(
                "Chat sends your messages, the images you attach and what the site's tools return to Anthropic, with this site's API key. Every message is billed to that key's Anthropic account; long conversations and images cost more.",
                "mcpmate",
              )}
            </p>
            <p className="m-0">
              {__(
                "Claude acts as you, through the same tools AI apps use: it can read the site at once, changes run only after you press Run, and destructive ones still wait for your approval. Everything it does is in the audit log.",
                "mcpmate",
              )}
            </p>
          </>
        )}
        {error && (
          <Notice
            tone="danger"
            title={__("Couldn't start Chat.", "mcpmate")}
            live
          >
            {error}
          </Notice>
        )}
        <div>
          <Button variant="primary" loading={busy} onClick={() => void start()}>
            {revoked
              ? __("Connect Chat again", "mcpmate")
              : __("Start using Chat", "mcpmate")}
          </Button>
        </div>
      </div>
    </Card>
  );
}

// ── The conversation ────────────────────────────

function ToolRow({ item }: { item: Extract<ChatItem, { kind: "tool" }> }) {
  return (
    <details className="el-chat-tool">
      <summary>
        <span className={cx("el-chat-dot", `is-${item.status}`)} aria-hidden />
        <code>{item.name}</code>
        <span className="el-chat-tool-status">{statusLabel(item.status)}</span>
      </summary>
      <div className="el-chat-tool-body">
        <JsonBlock
          value={item.input}
          label={__("What Claude sent", "mcpmate")}
        />
        {item.summary && <div className="el-chat-text">{item.summary}</div>}
        {item.status === "approval" && item.approval_id !== null && (
          <p className="m-0">
            <Link to={`/activity/approvals/${item.approval_id}`}>
              {__("Review it on Approvals", "mcpmate")}
            </Link>
          </p>
        )}
      </div>
    </details>
  );
}

function Item({ item }: { item: ChatItem }) {
  switch (item.kind) {
    case "user":
      return (
        <div className="el-chat-msg is-user">
          <p className="el-chat-who">{__("You", "mcpmate")}</p>
          {item.text && <div className="el-chat-text">{item.text}</div>}
          {item.images.length > 0 && (
            <ul
              className="el-chat-images"
              aria-label={__("Attached images", "mcpmate")}
            >
              {item.images.map((image, index) => (
                <li key={index}>
                  {sprintf(
                    /* translators: %s: file size, e.g. "1.2 MB". */
                    __("Image, %s", "mcpmate"),
                    bytesText(image.bytes),
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      );
    case "assistant":
      return (
        <div className="el-chat-msg is-assistant">
          <p className="el-chat-who">{__("Claude", "mcpmate")}</p>
          <div className="el-chat-text">{item.text}</div>
        </div>
      );
    case "thinking":
      return (
        <details className="el-chat-thinking">
          <summary>{__("Thinking", "mcpmate")}</summary>
          <div className="el-chat-text">{item.text}</div>
        </details>
      );
    case "tool":
      return <ToolRow item={item} />;
    default:
      return <Notice tone={item.tone} title={item.text} />;
  }
}

/** What has streamed in so far; replaced by the saved conversation once the step is done. */
function LiveItems({ live, sent }: { live: Live; sent: string | null }) {
  return (
    <>
      {sent !== null && (
        <div className="el-chat-msg is-user">
          <p className="el-chat-who">{__("You", "mcpmate")}</p>
          <div className="el-chat-text">{sent}</div>
        </div>
      )}
      {live.thinking && (
        <details className="el-chat-thinking">
          <summary>{__("Thinking…", "mcpmate")}</summary>
          <div className="el-chat-text">{live.thinking}</div>
        </details>
      )}
      {live.tools.map((tool) => (
        <p key={tool.id} className="el-chat-tool-live">
          <span
            className={cx("el-chat-dot", `is-${tool.status ?? "pending"}`)}
            aria-hidden
          />
          <code>{tool.name}</code>{" "}
          {tool.status ? statusLabel(tool.status) : __("Preparing…", "mcpmate")}
        </p>
      ))}
      {live.notices.map((notice, index) => (
        <Notice key={index} tone="info" title={notice} />
      ))}
      <div className="el-chat-msg is-assistant">
        <p className="el-chat-who">{__("Claude", "mcpmate")}</p>
        <div className="el-chat-text">
          {live.text || (
            <span className="el-chat-typing">{__("Working…", "mcpmate")}</span>
          )}
        </div>
      </div>
    </>
  );
}

function PlanCard({
  plan,
  onRun,
  onSkip,
}: {
  plan: ChatPlanCall[];
  onRun: () => void;
  onSkip: () => void;
}) {
  return (
    <Card title={planTitle(plan)} className="el-chat-plan">
      <ul className="el-chat-plan-list">
        {plan.map((call) => (
          <li key={call.id}>
            <code>{call.name}</code>{" "}
            <Badge
              variant={
                call.kind === "read"
                  ? "read"
                  : call.kind === "destructive"
                    ? "approval"
                    : "write"
              }
            >
              {kindLabel(call.kind)}
            </Badge>
          </li>
        ))}
      </ul>
      <p className="el-chat-plan-note">
        {__(
          "Open each call above to see what Claude will send. Run runs them as you; Skip tells Claude you chose not to.",
          "mcpmate",
        )}
      </p>
      <div className="el-chat-plan-actions">
        <Button variant="primary" onClick={onRun}>
          {__("Run", "mcpmate")}
        </Button>
        <Button variant="secondary" onClick={onSkip}>
          {__("Skip", "mcpmate")}
        </Button>
      </div>
    </Card>
  );
}

function Composer({
  busy,
  onSend,
}: {
  busy: boolean;
  /** Resolves false when the message did not reach the conversation: the draft comes back. */
  onSend: (text: string, files: File[]) => Promise<boolean>;
}) {
  const [text, setText] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [problem, setProblem] = useState("");
  const fileInput = useRef<HTMLInputElement>(null);

  const submit = (event?: FormEvent) => {
    event?.preventDefault();
    if (busy || (text.trim() === "" && files.length === 0)) {
      return;
    }
    const draft = { text, files };
    setText("");
    setFiles([]);
    setProblem("");
    void onSend(draft.text, draft.files).then((delivered) => {
      if (!delivered) {
        // Only into an empty composer: never over what was typed since.
        setText((current) => (current === "" ? draft.text : current));
        setFiles((current) => (current.length === 0 ? draft.files : current));
      }
    });
  };

  const attach = (event: ChangeEvent<HTMLInputElement>) => {
    const picked = Array.from(event.target.files ?? []);
    event.target.value = "";
    const why = imageProblem(picked, files.length);
    setProblem(why ?? "");
    if (!why) {
      setFiles((current) => [...current, ...picked]);
    }
  };

  const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    // Enter sends; Shift+Enter is a new line; never mid-composition (IME).
    if (
      event.key === "Enter" &&
      !event.shiftKey &&
      !event.nativeEvent.isComposing
    ) {
      submit();
      event.preventDefault();
    }
  };

  return (
    <form className="el-chat-composer" onSubmit={submit}>
      <label htmlFor="el-chat-message" className="sr-only">
        {__("Message", "mcpmate")}
      </label>
      <textarea
        id="el-chat-message"
        className="el-input el-chat-input"
        rows={3}
        value={text}
        placeholder={__(
          "Ask about this site, or ask Claude to change something…",
          "mcpmate",
        )}
        onChange={(event) => setText(event.target.value)}
        onKeyDown={onKeyDown}
        aria-describedby={problem ? "el-chat-problem" : "el-chat-help"}
      />
      <p id="el-chat-help" className="el-chat-help">
        {__("Enter sends, Shift+Enter starts a new line.", "mcpmate")}
      </p>
      {files.length > 0 && (
        <ul
          className="el-chat-images"
          aria-label={__("Images to send", "mcpmate")}
        >
          {files.map((file, index) => (
            <li key={`${file.name}-${index}`}>
              {file.name} ({bytesText(file.size)}){" "}
              <Button
                variant="link"
                size="sm"
                onClick={() =>
                  setFiles((current) => current.filter((_, i) => i !== index))
                }
                label={sprintf(
                  /* translators: %s: file name. */
                  __("Remove %s", "mcpmate"),
                  file.name,
                )}
              >
                {__("Remove", "mcpmate")}
              </Button>
            </li>
          ))}
        </ul>
      )}
      {problem && (
        <p id="el-chat-problem" className="el-field-error" role="alert">
          {problem}
        </p>
      )}
      <div className="el-chat-composer-actions">
        <input
          ref={fileInput}
          type="file"
          accept="image/jpeg,image/png,image/gif,image/webp"
          multiple
          className="sr-only"
          tabIndex={-1}
          aria-hidden
          onChange={attach}
        />
        <Button variant="secondary" onClick={() => fileInput.current?.click()}>
          {__("Attach images", "mcpmate")}
        </Button>
        <Button variant="primary" htmlType="submit" loading={busy}>
          {busy ? __("Claude is answering…", "mcpmate") : __("Send", "mcpmate")}
        </Button>
      </div>
    </form>
  );
}

// ── The workspace ───────────────────────────────

function Workspace() {
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();
  const announce = useAnnounce();
  const selected = sessionFromSearch(search);
  const [live, setLive] = useState<Live>(EMPTY_LIVE);
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState<string | null>(null);
  /** The conversation the running step belongs to ("new" until the server names it). */
  const [stepSession, setStepSession] = useState<number | "new" | null>(null);
  const [error, setError] = useState<StepError | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<number | null>(null);
  const end = useRef<HTMLDivElement>(null);
  const inFlight = useRef(false);
  const mounted = useRef(true);
  const selectedRef = useRef(selected);
  const resumed = useRef(new Set<number>());
  selectedRef.current = selected;

  useEffect(() => {
    // Set again on each setup: StrictMode runs the cleanup and the setup once more.
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const sessions = useQuery({
    queryKey: CHAT_SESSIONS_QUERY_KEY,
    queryFn: fetchChatSessions,
    staleTime: 0,
  });
  const session = useQuery({
    queryKey: sessionKey(selected),
    queryFn: () => fetchChatSession(selected as number),
    enabled: selected !== null,
    staleTime: 0,
  });

  useEffect(() => {
    end.current?.scrollIntoView?.({ block: "end" });
  }, [session.data, live, sent]);

  /** One streamed step for conversation `origin`; what it ended with, or null. */
  const runStep = async (
    route: "chat/send" | "chat/run" | "chat/retry",
    body: Record<string, unknown>,
    origin: number | "new",
  ): Promise<StepResult> => {
    setStepSession(origin);
    setLive(EMPTY_LIVE);
    setError(null);
    const result: StepResult & { id: number | null } = {
      session: null,
      error: null,
      id: origin === "new" ? null : origin,
    };

    await chatStream(route, body, (event) => {
      if (!mounted.current) {
        return;
      }
      if (event.type === "session") {
        result.id = event.data.id;
        setStepSession(event.data.id);
        // Only while the admin is still on the new chat this step started.
        if (selectedRef.current === null) {
          navigate(
            { search: `?${SESSION_PARAM}=${event.data.id}` },
            { replace: true },
          );
        }
      } else if (event.type === "done") {
        result.session = event.data.session;
      } else if (event.type === "error") {
        result.error = { ...event.data, sessionId: result.id };
        setError(result.error);
      } else {
        setLive((current) => applyEvent(current, event));
      }
    });

    void queryClient.invalidateQueries({ queryKey: CHAT_SESSIONS_QUERY_KEY });
    if (result.session) {
      queryClient.setQueryData(sessionKey(result.session.id), result.session);
    } else if (result.id !== null) {
      void queryClient.invalidateQueries({ queryKey: sessionKey(result.id) });
    }

    return { session: result.session, error: result.error };
  };

  /** Reads run without asking: keep going while the server says `auto`, then say how it ended. */
  const finish = async (first: ChatSession | null) => {
    let next = first;
    while (next && next.state === "auto") {
      next = (
        await runStep(
          "chat/run",
          { session_id: next.id, approve: false },
          next.id,
        )
      ).session;
    }
    if (mounted.current) {
      announce(
        !next
          ? __("Chat stopped with an error.", "mcpmate")
          : next.state === "plan"
            ? __("Claude is waiting for you to review its changes.", "mcpmate")
            : __("Claude answered.", "mcpmate"),
      );
    }
  };

  /** At most one step at a time, from the click until it ends, whatever happens. */
  const guarded = async (work: () => Promise<void>) => {
    if (inFlight.current) {
      return;
    }
    inFlight.current = true;
    setBusy(true);
    try {
      await work();
    } catch {
      if (mounted.current) {
        setError({
          code: "mcpmate_chat_failed",
          message: __("Chat stopped with an error.", "mcpmate"),
          sessionId: selectedRef.current,
        });
      }
    } finally {
      inFlight.current = false;
      if (mounted.current) {
        setBusy(false);
        setLive(EMPTY_LIVE);
        setSent(null);
        setStepSession(null);
      }
    }
  };

  /** True once the message is in the conversation (answered or not). */
  const send = async (text: string, files: File[]): Promise<boolean> => {
    let delivered = false;
    await guarded(async () => {
      const origin = selectedRef.current ?? "new";
      setStepSession(origin);
      setSent(text.trim() || __("(images)", "mcpmate"));
      let images: string[];
      try {
        images = await Promise.all(files.map(fileToBase64));
      } catch {
        setError({
          code: "read",
          message: __("An image could not be read.", "mcpmate"),
          sessionId: origin === "new" ? null : origin,
        });
        return;
      }
      const first = await runStep(
        "chat/send",
        { session_id: origin === "new" ? 0 : origin, text, images },
        origin,
      );
      delivered = first.session !== null || first.error?.kept === true;
      await finish(first.session);
    });
    return delivered;
  };

  const run = (id: number, approve: boolean) =>
    guarded(async () => {
      await finish(
        (await runStep("chat/run", { session_id: id, approve }, id)).session,
      );
    });

  const retry = (id: number) =>
    guarded(async () => {
      await finish(
        (await runStep("chat/retry", { session_id: id }, id)).session,
      );
    });

  // A conversation left waiting for its reads (the tab closed mid-step) goes on when it is opened.
  useEffect(() => {
    const opened = session.data;
    if (
      selected !== null &&
      opened?.id === selected &&
      opened.state === "auto" &&
      !inFlight.current &&
      !resumed.current.has(opened.id)
    ) {
      resumed.current.add(opened.id);
      void run(opened.id, false);
    }
    // run is recreated each render; the guard and the Set keep this to one resume.
    // busy: a conversation opened while another step ran goes on once that step ends.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected, session.data, busy]);

  const remove = async (id: number) => {
    queryClient.setQueryData(
      CHAT_SESSIONS_QUERY_KEY,
      await deleteChatSession(id),
    );
    // The conversation open now, not the one open when Delete was pressed.
    if (id === selectedRef.current) {
      navigate({ search: "" }, { replace: true });
    }
    announce(__("Conversation deleted.", "mcpmate"));
  };

  const current = selected !== null ? session.data : undefined;
  const plan = !busy && current?.state === "plan" ? current.plan : null;
  // What streams in, and what failed, belong to the conversation that started the step.
  const showLive =
    busy &&
    (stepSession === selected || (stepSession === "new" && selected === null));
  const shownError = error && error.sessionId === selected ? error : null;

  return (
    <div className="el-chat">
      <nav
        className="el-chat-sessions"
        aria-label={__("Conversations", "mcpmate")}
      >
        <Button
          variant="secondary"
          onClick={() => navigate({ search: "" })}
          disabled={busy}
        >
          {__("New chat", "mcpmate")}
        </Button>
        {sessions.isLoading ? (
          <Skeleton count={3} />
        ) : (
          <ul className="el-chat-session-list">
            {(sessions.data ?? []).map((item) => (
              <li
                key={item.id}
                className={cx(item.id === selected && "is-current")}
              >
                <Link
                  to={{ search: `?${SESSION_PARAM}=${item.id}` }}
                  aria-current={item.id === selected ? "page" : undefined}
                >
                  {item.title || __("Untitled", "mcpmate")}
                </Link>
                <Button
                  variant="link"
                  size="sm"
                  disabled={busy}
                  onClick={() => setConfirmDelete(item.id)}
                  label={sprintf(
                    /* translators: %s: conversation title. */
                    __("Delete %s", "mcpmate"),
                    item.title,
                  )}
                >
                  {__("Delete", "mcpmate")}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </nav>

      <section
        className="el-chat-main"
        aria-label={current?.title || __("New chat", "mcpmate")}
      >
        <div className="el-chat-transcript">
          {selected === null && !busy && sent === null && (
            <EmptyState
              title={__("Ask about this site", "mcpmate")}
              body={__(
                "For example: which drafts haven't been touched in a month? Or: add an FAQ section to the About page draft.",
                "mcpmate",
              )}
            />
          )}
          {selected !== null && session.isLoading && <Skeleton count={4} />}
          {selected !== null && session.isError && (
            <Notice
              tone="danger"
              title={__("Couldn't load this conversation.", "mcpmate")}
            >
              {requestErrorText(session.error)}
            </Notice>
          )}
          {current?.items.map((item, index) => (
            <Item key={index} item={item} />
          ))}
          {showLive && <LiveItems live={live} sent={sent} />}
          <div ref={end} />
        </div>

        {plan && plan.length > 0 && (
          <PlanCard
            plan={plan}
            onRun={() => current && void run(current.id, true)}
            onSkip={() => current && void run(current.id, false)}
          />
        )}

        {shownError && (
          <Notice
            tone="danger"
            title={shownError.message}
            live
            action={
              shownError.sessionId !== null &&
              shownError.code !== "mcpmate_chat_busy" ? (
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={busy}
                  onClick={() =>
                    shownError.sessionId !== null &&
                    void retry(shownError.sessionId)
                  }
                >
                  {__("Try again", "mcpmate")}
                </Button>
              ) : undefined
            }
          >
            {shownError.hint}
          </Notice>
        )}

        <Composer busy={busy} onSend={send} />
      </section>

      <ConfirmDialog
        open={confirmDelete !== null}
        tone="danger"
        title={__("Delete this conversation?", "mcpmate")}
        body={__(
          "The conversation and its images are deleted from this site. Nothing Claude changed on the site is undone, and the audit log keeps its rows.",
          "mcpmate",
        )}
        confirmLabel={__("Delete", "mcpmate")}
        busyLabel={__("Deleting…", "mcpmate")}
        cancelLabel={__("Cancel", "mcpmate")}
        onConfirm={async () => {
          if (confirmDelete !== null) {
            await remove(confirmDelete);
          }
        }}
        onClose={() => setConfirmDelete(null)}
      />
    </div>
  );
}

/**
 * Chat (`#/chat`; MCPMate Pro, docs/pro-parity.md P22): a conversation
 * with Claude about this site, run with this site's Anthropic key and the
 * same MCP tools AI apps use, as the signed-in admin. Reads run at once;
 * writes wait for Run on the plan; destructive ones then wait on Approvals.
 * The open conversation is `?session=<id>`.
 */
export default function Chat() {
  const settings = useChatSettings();

  let body;
  if (!settings || !settings.ready) {
    body = (
      <EmptyState
        title={__("Chat isn't set up", "mcpmate")}
        body={__(
          "A site admin can add an Anthropic API key and turn Chat on in Settings.",
          "mcpmate",
        )}
        action={{
          label: __("Open Chat settings", "mcpmate"),
          href: "#/settings?section=chat",
        }}
      />
    );
  } else if (!settings.consent || !settings.connected) {
    body = <ConsentCard settings={settings} />;
  } else {
    body = <Workspace />;
  }

  return (
    <>
      <PageHeader
        title={__("Chat", "mcpmate")}
        intro={__(
          "Ask Claude about this site, and let it make the changes you approve.",
          "mcpmate",
        )}
      />
      {body}
    </>
  );
}
