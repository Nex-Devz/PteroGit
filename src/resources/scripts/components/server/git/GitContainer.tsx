import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { useHistory, useLocation } from 'react-router';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSyncAlt, faDownload, faUpload, faTrashAlt, faCodeBranch, faInfoCircle, faExchangeAlt, faHistory, faPlus, faUndo, faKey } from '@fortawesome/free-solid-svg-icons';
import { faGithub } from '@fortawesome/free-brands-svg-icons';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import FlashMessageRender from '@/components/FlashMessageRender';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import ContentBox from '@/components/elements/ContentBox';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Input from '@/components/elements/Input';
import Label from '@/components/elements/Label';
import { Button } from '@/components/elements/button/index';
import { Dialog } from '@/components/elements/dialog';
import Can from '@/components/elements/Can';
import { useFlashKey } from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import { useStoreState } from 'easy-peasy';
import {
    getGitStatus,
    GitStatus,
    GitChange,
    GitModuleState,
    pullRepository,
    pushRepository,
    stageFiles,
    unstageFiles,
    discardFiles,
    commitChanges,
    connectRepository,
    disconnectRepository,
    getGitDiff,
    getCommitHistory,
    getGitignore,
    saveGitignore,
    getGitIdentity,
    saveGitIdentity,
} from '@/api/server/git';
import {
    getGithubAccounts,
    searchGithubRepositories,
    GithubAccount,
    GithubRepository,
} from '@/api/account/github';

type TabKey = 'overview' | 'changes' | 'history';

const TAB_KEYS: TabKey[] = ['overview', 'changes', 'history'];

// The active tab is mirrored into the URL as a bare query flag so links like
// /server/<uuid>/git?changes or /git?history open on that tab directly.
const tabFromSearch = (search: string): TabKey => {
    const params = new URLSearchParams(search);

    for (const key of TAB_KEYS) {
        if (params.has(key)) {
            return key;
        }
    }

    const explicit = params.get('tab');
    if (explicit && (TAB_KEYS as string[]).includes(explicit)) {
        return explicit as TabKey;
    }

    return 'overview';
};

const STATUS_ICONS: Record<string, string> = {
    added: '+',
    deleted: '-',
    untracked: '?',
    modified: 'M',
    renamed: 'R',
    unmerged: 'U',
    copied: 'C',
};

const STATUS_COLORS: Record<string, any> = {
    added: tw`bg-green-900 text-green-200`,
    deleted: tw`bg-red-900 text-red-200`,
    untracked: tw`bg-neutral-700 text-neutral-300`,
    modified: tw`bg-yellow-900 text-yellow-200`,
    unmerged: tw`bg-red-900 text-red-200`,
    renamed: tw`bg-blue-900 text-blue-200`,
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('git');
    const user = useStoreState((state: any) => state.user.data);
    const location = useLocation();
    const history = useHistory();

    const [status, setStatus] = useState<GitStatus | null>(null);
    const [module, setModule] = useState<GitModuleState | null>(null);
    const [githubAccounts, setGithubAccounts] = useState<GithubAccount[]>([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [authError, setAuthError] = useState(false);
    const [tab, setTab] = useState<TabKey>(() => tabFromSearch(location.search));
    const [connectVisible, setConnectVisible] = useState(false);
    const [disconnectVisible, setDisconnectVisible] = useState(false);

    // Keep the selected tab in sync with the URL so back/forward navigation
    // and direct links (?changes, ?history) always land on the right tab.
    useEffect(() => {
        setTab(tabFromSearch(location.search));
    }, [location.search]);

    const switchTab = (next: TabKey) => {
        setTab(next);
        // Overview is the default and gets a clean URL without a query string.
        history.push({ search: next === 'overview' ? '' : `?${next}` });
    };

    const refresh = (showSpinner = true) => {
        if (showSpinner) {
            setLoading(true);
        }
        setLoadError(false);
        setAuthError(false);

        return getGitStatus(uuid)
            .then(({ data }) => {
                setStatus(data.status);
                setModule(data.module);
                setGithubAccounts(Array.isArray(data.accounts) ? data.accounts : []);
            })
            .catch((error) => {
                if (error?.response?.status === 401) {
                    setAuthError(true);
                } else {
                    clearAndAddHttpError(error);
                }
                setLoadError(true);
            })
            .then(() => setLoading(false));
    };

    useEffect(() => {
        refresh();
    }, [uuid]);

    const onPull = () => {
        setLoading(true);
        clearFlashes();

        pullRepository(uuid)
            .then(() => refresh(false))
            .catch((error) => {
                clearAndAddHttpError(error);
                setLoading(false);
            });
    };

    const onPush = () => {
        setLoading(true);
        clearFlashes();

        pushRepository(uuid)
            .then(() => refresh(false))
            .catch((error) => {
                clearAndAddHttpError(error);
                setLoading(false);
            });
    };

    const onDisconnect = () => {
        setLoading(true);
        clearFlashes();

        disconnectRepository(uuid)
            .then(() => {
                setStatus((s) => (s ? { ...s, connected: false, repository: null } : s));
                setDisconnectVisible(false);
                setLoading(false);
            })
            .catch((error) => {
                clearAndAddHttpError(error);
                setLoading(false);
            });
    };

    if (module === null) {
        return (
            <ServerContentBlock title={'GitHub'}>
                <FlashMessageRender byKey={'git'} css={tw`mb-4`} />
                <SpinnerOverlay visible={loading} />
                {!loading && authError && (
                    <ContentBox title={'Login Required'}>
                        <div css={tw`text-center p-4`}>
                            <FontAwesomeIcon icon={faGithub} size={'2x'} css={tw`mb-3 text-neutral-400`} />
                            <p css={tw`text-sm text-neutral-300 mb-4`}>
                                Log in to your panel account to access the GitHub integration.
                            </p>
                            <a
                                href={'/auth/login'}
                                css={tw`inline-flex items-center gap-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-medium rounded transition-colors`}
                            >
                                <FontAwesomeIcon icon={faKey} css={tw`mr-2`} />
                                Log In
                            </a>
                        </div>
                    </ContentBox>
                )}
                {!loading && loadError && !authError && (
                    <ContentBox title={'Unable to load'}>
                        <div css={tw`text-center p-4`}>
                            <p css={tw`text-sm text-neutral-400 mb-4`}>
                                Could not load the GitHub integration. You may not have permission to access this feature,
                                or it may be temporarily unavailable.
                            </p>
                            <Button variant={Button.Variants.Secondary} onClick={() => refresh()}>
                                Try Again
                            </Button>
                        </div>
                    </ContentBox>
                )}
            </ServerContentBlock>
        );
    }

    if (!module.enabled) {
        return (
            <ServerContentBlock title={'GitHub'}>
                <FlashMessageRender byKey={'git'} css={tw`mb-4`} />
                <ContentBox title={'Module Disabled'}>
                    <div css={tw`text-center p-4`}>
                        <FontAwesomeIcon icon={faSyncAlt} size={'2x'} css={tw`mb-3 text-neutral-400`} />
                        <p css={tw`text-sm text-neutral-400`}>
                            The GitHub integration is currently disabled by an administrator.
                            {module.admin && ' Administrators may re-enable the module from the admin settings page.'}
                        </p>
                    </div>
                </ContentBox>
            </ServerContentBlock>
        );
    }

    if (status && !status.connected) {
        return (
            <ServerContentBlock title={'GitHub'}>
                <FlashMessageRender byKey={'git'} css={tw`mb-4`} />
                <ConnectRepositoryDialog
                    visible={connectVisible}
                    onClose={() => setConnectVisible(false)}
                    onConnected={() => refresh()}
                    moduleEnabled={!!module?.enabled}
                />
                {user && <UserBar user={user} githubAccounts={githubAccounts} />}
                <ContentBox title={'Connect a repository'}>
                    <SpinnerOverlay visible={loading} />
                    <p css={tw`text-sm text-neutral-300`}>
                        This server is not linked to a GitHub repository. Connect one to manage changes, commits and
                        deployment from the panel.
                    </p>
                    <div css={tw`mt-6`}>
                        <Button onClick={() => setConnectVisible(true)}>
                            <FontAwesomeIcon icon={faCodeBranch} css={tw`mr-2`} />
                            Connect Repository
                        </Button>
                    </div>
                </ContentBox>
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'GitHub'}>
            <FlashMessageRender byKey={'git'} css={tw`mb-4`} />
            {user && <UserBar user={user} githubAccounts={githubAccounts} />}

            <div css={tw`flex flex-wrap items-center gap-2 mb-4`}>

                <div css={tw`flex items-center border-b border-neutral-700 w-full mb-1`}>
                    <TabButton active={tab === 'overview'} onClick={() => switchTab('overview')} icon={faInfoCircle}>
                        Overview
                    </TabButton>
                    <TabButton active={tab === 'changes'} onClick={() => switchTab('changes')} icon={faExchangeAlt} badge={status && status.is_dirty ? status.changes.length : undefined}>
                        Changes
                    </TabButton>
                    <TabButton active={tab === 'history'} onClick={() => switchTab('history')} icon={faHistory}>
                        History
                    </TabButton>
                </div>

                <div css={tw`flex-1`} />

                <Can action={'git.manage-repository'}>
                    <Button.Danger variant={Button.Variants.Secondary} onClick={() => setDisconnectVisible(true)}>
                        <FontAwesomeIcon icon={faTrashAlt} css={tw`mr-2`} />
                        Disconnect
                    </Button.Danger>
                </Can>
                <Button variant={Button.Variants.Secondary} onClick={() => refresh()}>
                    <FontAwesomeIcon icon={faSyncAlt} css={tw`mr-2`} />
                    Refresh
                </Button>
                <Can action={'git.pull'}>
                    <Button variant={Button.Variants.Secondary} onClick={onPull}>
                        <FontAwesomeIcon icon={faDownload} css={tw`mr-2`} />
                        Pull
                    </Button>
                </Can>
                <Can action={'git.push'}>
                    <Button onClick={onPush} disabled={!status || status.ahead === 0}>
                        <FontAwesomeIcon icon={faUpload} css={tw`mr-2`} />
                        Push
                        {status && status.ahead > 0 && <span css={tw`ml-2`}>({status.ahead})</span>}
                    </Button>
                </Can>
            </div>

            <SpinnerOverlay visible={loading} fixed />

            {status && (
                <>
                    {tab === 'overview' && <Overview status={status} uuid={uuid} />}
                    {tab === 'changes' && <Changes status={status} uuid={uuid} onChange={() => refresh()} />}
                    {tab === 'history' && <HistoryTree uuid={uuid} />}
                </>
            )}

            <Dialog.Confirm
                open={disconnectVisible}
                onClose={() => setDisconnectVisible(false)}
                title={'Disconnect repository'}
                confirm={'Disconnect'}
                onConfirmed={onDisconnect}
            >
                Disconnecting this server removes the GitHub link. Existing files in the server directory are kept.
            </Dialog.Confirm>

            <ConnectRepositoryDialog
                visible={connectVisible}
                onClose={() => setConnectVisible(false)}
                onConnected={() => refresh()}
                moduleEnabled={!!module?.enabled}
            />
        </ServerContentBlock>
    );
};

// Bar showing the logged-in panel user and any linked GitHub accounts.
const UserBar = ({ user, githubAccounts }: { user: { username: string }; githubAccounts: GithubAccount[] }) => {
    const initials = user.username.slice(0, 2).toUpperCase();

    return (
        <div css={tw`inline-flex flex-wrap items-center gap-3 mb-3 px-3 py-2 bg-neutral-800 rounded-lg`}>
            {/* Panel user */}
            <div css={tw`flex items-center gap-2`}>
                <div css={tw`w-6 h-6 rounded-full bg-cyan-700 flex items-center justify-center text-white text-xs font-bold select-none`}>
                    {initials}
                </div>
                <span css={tw`text-xs text-neutral-300`}>
                    Logged in as <span css={tw`text-neutral-100 font-medium`}>{user.username}</span>
                </span>
            </div>

            {/* Linked GitHub accounts */}
            {githubAccounts.length > 0 && (
                <>
                    <span css={tw`text-neutral-600 text-xs`}>·</span>
                    <div css={tw`flex items-center gap-2`}>
                        <FontAwesomeIcon icon={faGithub} css={tw`text-neutral-400 text-xs`} />
                        <span css={tw`text-xs text-neutral-400`}>GitHub:</span>
                        {githubAccounts.map((acct) => (
                            <div key={acct.id} css={tw`flex items-center gap-1`}>
                                {acct.avatar_url ? (
                                    <img src={acct.avatar_url} css={tw`w-5 h-5 rounded-full`} alt={acct.username} />
                                ) : (
                                    <div css={tw`w-5 h-5 rounded-full bg-neutral-600 flex items-center justify-center text-white text-xs font-bold`}>
                                        {acct.username.slice(0, 1).toUpperCase()}
                                    </div>
                                )}
                                <span css={tw`text-xs text-neutral-200 font-medium`}>{acct.username}</span>
                            </div>
                        ))}
                    </div>
                </>
            )}

            {githubAccounts.length === 0 && (
                <>
                    <span css={tw`text-neutral-600 text-xs`}>·</span>
                    <a href={'/account/github'} css={tw`text-xs text-cyan-400 hover:text-cyan-300 flex items-center gap-1`}>
                        <FontAwesomeIcon icon={faGithub} css={tw`text-xs`} />
                        Connect GitHub
                    </a>
                </>
            )}
        </div>
    );
};

const TabButton = ({
    active,
    onClick,
    icon,
    badge,
    children,
}: {
    active: boolean;
    onClick: () => void;
    icon?: any;
    badge?: number;
    children: React.ReactNode;
}) => (
    <button
        onClick={onClick}
        css={[
            tw`flex items-center gap-2 px-4 py-2.5 text-sm font-medium -mb-px border-b-2 transition-colors`,
            active
                ? tw`border-cyan-500 text-cyan-300`
                : tw`border-transparent text-neutral-400 hover:text-neutral-200 hover:border-neutral-500`,
        ]}
    >
        {icon && <FontAwesomeIcon icon={icon} css={tw`text-xs`} />}
        {children}
        {badge !== undefined && (
            <span
                css={[
                    tw`inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-xs font-bold`,
                    active ? tw`bg-cyan-600 text-white` : tw`bg-yellow-500/90 text-neutral-900`,
                ]}
            >
                {badge}
            </span>
        )}
    </button>
);

const Overview = ({ status, uuid }: { status: GitStatus; uuid: string }) => (
    <>
        {status.repository && (
            <ContentBox title={status.repository.repository_full_name ?? 'Repository'}>
                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                    <InfoCard icon={faCodeBranch} label={'Current branch'} value={status.current_branch ?? '—'} />
                    <InfoCard icon={faInfoCircle} label={'Default branch'} value={status.repository?.default_branch ?? '—'} />
                    <InfoCard icon={faUpload} label={'Ahead'} value={`${status.ahead} commit${status.ahead === 1 ? '' : 's'}`} />
                    <InfoCard icon={faDownload} label={'Behind'} value={`${status.behind} commit${status.behind === 1 ? '' : 's'}`} />
                </div>
                <div css={tw`mt-6 flex items-center justify-between`}>
                    <span css={tw`text-xs text-neutral-500`}>
                        Last commit: <span css={tw`text-neutral-300`}>{status.last_commit ?? 'None yet'}</span>
                        {status.last_commit_message ? ` — ${status.last_commit_message}` : ''}
                    </span>
                    {status.repository?.account_username && (
                        <span css={tw`text-xs text-neutral-500`}>
                            Connected as <span css={tw`text-neutral-300`}>{status.repository.account_username}</span>
                        </span>
                    )}
                </div>
            </ContentBox>
        )}

        <GitignoreBox uuid={uuid} />
        <IdentityBox uuid={uuid} />
    </>
);

const InfoCard = ({ icon, label, value }: { icon: any; label: string; value: string }) => (
    <div css={tw`bg-neutral-800 border border-neutral-700/60 rounded-lg p-4 flex items-center hover:border-neutral-600 transition-colors`}>
        <div css={tw`w-9 h-9 rounded-lg bg-neutral-700/60 flex items-center justify-center mr-4`}>
            <FontAwesomeIcon icon={icon} css={tw`text-cyan-400`} />
        </div>
        <div css={tw`min-w-0`}>
            <p css={tw`text-xs text-neutral-500 uppercase tracking-wide`}>{label}</p>
            <p css={tw`text-sm text-neutral-100 truncate`}>{value}</p>
        </div>
    </div>
);

const Changes = ({ status, uuid, onChange }: { status: GitStatus; uuid: string; onChange: () => void }) => {
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('git');
    const [busy, setBusy] = useState<string | null>(null);

    const batch = (action: (uuid: string, files: string[]) => Promise<any>, files: string[], key: string) => {
        if (!files.length) {
            return;
        }

        setBusy(key);
        clearFlashes();

        // The API helpers take (uuid, files) — passing the file array into the
        // uuid slot produced requests like /servers/<file>/github/stage.
        action(uuid, files)
            .then(onChange)
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(null));
    };

    const fileAction = (file: string, action: 'stage' | 'unstage' | 'discard') => {
        setBusy(`file:${file}`);
        clearFlashes();

        const call =
            action === 'stage'
                ? () => stageFiles(uuid, [file])
                : action === 'unstage'
                ? () => unstageFiles(uuid, [file])
                : () => discardFiles(uuid, [file]);

        call()
            .then(onChange)
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(null));
    };

    const staged = status.changes.filter((c) => c.staged);
    const unstaged = status.changes.filter((c) => !c.staged);

    return (
        <>
            <SpinnerOverlay visible={!!busy} fixed />
            <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4`}>
                <ChangeList
                    title={'Staged Changes'}
                    changes={staged}
                    empty={'Nothing staged. Stage files below to include them in the next commit.'}
                    color={tw`text-cyan-300`}
                    busy={busy}
                    onFileAction={(file, action) => fileAction(file, action)}
                    actions={
                        <Can action={'git.commit'}>
                            <Button variant={Button.Variants.Secondary} onClick={() => batch(unstageFiles, staged.map((c) => c.file), 'unstage')}>
                                Unstage all
                            </Button>
                        </Can>
                    }
                />
                <ChangeList
                    title={'Unstaged Changes'}
                    changes={unstaged}
                    empty={'Working directory is clean.'}
                    color={tw`text-yellow-300`}
                    busy={busy}
                    onFileAction={(file, action) => fileAction(file, action)}
                    actions={
                        <Can action={'git.commit'}>
                            <Button variant={Button.Variants.Secondary} onClick={() => batch(stageFiles, unstaged.map((c) => c.file), 'stage')}>
                                Stage all
                            </Button>
                        </Can>
                    }
                />
            </div>

            {status.changes.length > 0 && (
                <div css={tw`mt-4`}>
                    <Can action={'git.commit'}>
                        <CommitForm uuid={uuid} onChange={onChange} hasStaged={staged.length > 0} />
                    </Can>
                    <Can action={'git.commit'}>
                        <DiscardAllRow changes={status.changes} uuid={uuid} onChange={onChange} />
                    </Can>
                    <Can action={'git.commit'}>
                        <StagedDiff uuid={uuid} changes={staged} />
                    </Can>
                </div>
            )}
        </>
    );
};

const ChangeList = ({
    title,
    changes,
    empty,
    color,
    actions,
    onFileAction,
    busy,
}: {
    title: string;
    changes: GitChange[];
    empty: string;
    color: any;
    actions: React.ReactNode;
    onFileAction?: (file: string, action: 'stage' | 'unstage' | 'discard') => void;
    busy?: string | null;
}) => (
    <ContentBox title={title}>
        <div css={tw`flex items-center justify-end mb-2`}>{changes.length > 0 && actions}</div>
        {changes.length === 0 ? (
            <p css={tw`text-sm text-neutral-500 p-2 italic`}>{empty}</p>
        ) : (
            <div css={tw`divide-y divide-neutral-700/60`}>
                {changes.map((change) => (
                    <div key={change.file} css={tw`flex items-center py-2.5`}>
                        <span
                            css={[
                                tw`inline-flex justify-center items-center w-6 h-6 rounded text-xs font-bold mr-3 flex-shrink-0`,
                                STATUS_COLORS[change.status] ?? tw`bg-neutral-700 text-neutral-300`,
                            ]}
                        >
                            {STATUS_ICONS[change.status] ?? '*'}
                        </span>
                        <code css={tw`flex-1 text-xs text-neutral-200 break-all mr-3`}>{change.file}</code>
                        <span css={[tw`text-xs uppercase tracking-wider mr-3 hidden sm:inline`, color]}>{change.status}</span>
                        {onFileAction && (
                            <Can action={'git.commit'}>
                                <div css={tw`flex items-center gap-1 flex-shrink-0`}>
                                    {change.staged ? (
                                        <Button
                                            variant={Button.Variants.Secondary}
                                            onClick={() => onFileAction(change.file, 'unstage')}
                                            disabled={!!busy}
                                            css={tw`!px-2 !py-1 text-xs`}
                                        >
                                            <FontAwesomeIcon icon={faUndo} css={tw`mr-1`} />
                                            Unstage
                                        </Button>
                                    ) : (
                                        <>
                                            <Button
                                                onClick={() => onFileAction(change.file, 'stage')}
                                                disabled={!!busy}
                                                css={tw`!px-2 !py-1 text-xs`}
                                            >
                                                <FontAwesomeIcon icon={faPlus} css={tw`mr-1`} />
                                                Stage
                                            </Button>
                                            <Button.Danger
                                                variant={Button.Variants.Secondary}
                                                onClick={() => onFileAction(change.file, 'discard')}
                                                disabled={!!busy}
                                                css={tw`!px-2 !py-1 text-xs`}
                                            >
                                                <FontAwesomeIcon icon={faTrashAlt} />
                                            </Button.Danger>
                                        </>
                                    )}
                                </div>
                            </Can>
                        )}
                    </div>
                ))}
            </div>
        )}
    </ContentBox>
);

const CommitForm = ({ uuid, onChange, hasStaged }: { uuid: string; onChange: () => void; hasStaged: boolean }) => {
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('git');
    const [message, setMessage] = useState('');
    const [push, setPush] = useState(false);
    const [all, setAll] = useState(false);
    const [busy, setBusy] = useState(false);

    const submit = () => {
        if (!message.trim()) {
            return;
        }

        setBusy(true);
        clearFlashes();

        commitChanges(uuid, message.trim(), push, all)
            .then(() => {
                setMessage('');
                onChange();
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(false));
    };

    return (
        <TitledGreyBox title={'Commit Changes'}>
            <SpinnerOverlay visible={busy} />
            <textarea
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                placeholder={'Commit message'}
                css={tw`w-full bg-neutral-800 border border-neutral-600 rounded p-3 text-sm text-neutral-100 min-h-[90px]`}
            />
            {!hasStaged && !all && (
                <p css={tw`text-xs text-yellow-300 mt-2`}>
                    Nothing is staged yet — enable “stage all” below or stage files first.
                </p>
            )}
            <div css={tw`flex flex-wrap items-center justify-end mt-4 gap-2`}>
                <label css={tw`flex items-center text-sm text-neutral-300 mr-2`}>
                    <input type={'checkbox'} checked={all} onChange={(e) => setAll(e.target.checked)} css={tw`mr-2`} />
                    Stage all changes
                </label>
                <label css={tw`flex items-center text-sm text-neutral-300 mr-2`}>
                    <input type={'checkbox'} checked={push} onChange={(e) => setPush(e.target.checked)} css={tw`mr-2`} />
                    Push after commit
                </label>
                <Button onClick={submit} disabled={!message.trim() || busy || (!hasStaged && !all)}>
                    Commit
                </Button>
            </div>
        </TitledGreyBox>
    );
};

const DiscardAllRow = ({ changes, uuid, onChange }: { changes: GitChange[]; uuid: string; onChange: () => void }) => {
    const [visible, setVisible] = useState(false);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('git');

    const doDiscard = () => {
        clearFlashes();

        discardFiles(uuid, changes.filter((c) => !c.staged).map((c) => c.file))
            .then(() => {
                setVisible(false);
                onChange();
            })
            .catch((error) => clearAndAddHttpError(error));
    };

    return (
        <>
            <div css={tw`mt-4 text-right`}>
                <Button.Danger variant={Button.Variants.Secondary} onClick={() => setVisible(true)}>
                    Discard all unstaged changes
                </Button.Danger>
            </div>
            <Dialog.Confirm
                open={visible}
                onClose={() => setVisible(false)}
                title={'Discard changes'}
                confirm={'Discard'}
                onConfirmed={doDiscard}
            >
                This permanently discards all unstaged changes on the server. This cannot be undone.
            </Dialog.Confirm>
        </>
    );
};

const DiffLine = ({ line }: { line: string }) => {
    const css =
        line.startsWith('+') && !line.startsWith('+++')
            ? tw`bg-green-900/40 text-green-200`
            : line.startsWith('-') && !line.startsWith('---')
            ? tw`bg-red-900/40 text-red-200`
            : line.startsWith('@@')
            ? tw`text-cyan-400`
            : tw`text-neutral-300`;

    return <div css={[tw`px-2 whitespace-pre-wrap break-all`, css]}>{line || ' '}</div>;
};

const StagedDiff = ({ uuid, changes }: { uuid: string; changes: GitChange[] }) => {
    const [diff, setDiff] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const file = changes.length > 0 ? changes[0].file : null;

    useEffect(() => {
        setLoading(true);
        setDiff(null);

        if (!file) {
            setLoading(false);
            return;
        }

        getGitDiff(uuid, file)
            .then(setDiff)
            .catch(() => setDiff(null))
            .then(() => setLoading(false));
    }, [uuid, file]);

    return (
        <TitledGreyBox title={'Diff Preview'} css={tw`mt-4`}>
            <SpinnerOverlay visible={loading} />
            {file && (
                <p css={tw`text-xs text-neutral-500 mb-2`}>
                    Showing staged diff for <code css={tw`text-neutral-300`}>{file}</code>
                    {changes.length > 1 && ` (1 of ${changes.length} staged files)`}
                </p>
            )}
            {diff && diff.trim() !== '' ? (
                <div css={tw`rounded bg-neutral-900 border border-neutral-700/60 py-2 max-h-96 overflow-y-auto font-mono text-xs`}>
                    {diff.split('\n').map((line, i) => (
                        <DiffLine key={i} line={line} />
                    ))}
                </div>
            ) : (
                <p css={tw`text-sm text-neutral-500 italic`}>No diff to display.</p>
            )}
        </TitledGreyBox>
    );
};

const HistoryTree = ({ uuid }: { uuid: string }) => {
    const [commits, setCommits] = useState<{ short: string; subject: string; author: string; relative: string }[]>([]);
    const [loading, setLoading] = useState(true);
    const { clearAndAddHttpError } = useFlashKey('git');

    useEffect(() => {
        setLoading(true);

        getCommitHistory(uuid)
            .then(setCommits)
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setLoading(false));
    }, [uuid]);

    return (
        <ContentBox title={'Commit History'}>
            <SpinnerOverlay visible={loading} />
            {commits.length === 0 ? (
                <p css={tw`text-sm text-neutral-500 p-2 italic`}>No commits yet.</p>
            ) : (
                <div css={tw`relative`}>
                    {commits.map((commit, index) => (
                        <div key={commit.short} css={tw`flex`}>
                            <div css={tw`flex flex-col items-center mr-4`}>
                                <span css={tw`w-2.5 h-2.5 rounded-full bg-cyan-500 ring-4 ring-cyan-500/20 flex-shrink-0 mt-1.5`} />
                                {index < commits.length - 1 && <span css={tw`w-px flex-1 bg-neutral-700 my-0.5`} />}
                            </div>
                            <div css={[tw`py-2 flex-1 min-w-0`, index < commits.length - 1 && tw`pb-6`]}>
                                <p css={tw`text-sm text-neutral-100 break-words`}>{commit.subject}</p>
                                <p css={tw`text-xs text-neutral-500 mt-0.5`}>
                                    <code css={tw`text-cyan-400 mr-2`}>{commit.short}</code>
                                    {commit.author} · {commit.relative}
                                </p>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </ContentBox>
    );
};

const GitignoreBox = ({ uuid }: { uuid: string }) => {
    const [value, setValue] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('git');

    useEffect(() => {
        getGitignore(uuid)
            .then(setValue)
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setLoading(false));
    }, [uuid]);

    const save = () => {
        setSaving(true);
        clearFlashes();

        saveGitignore(uuid, value)
            .then(() => clearFlashes())
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setSaving(false));
    };

    return (
        <Can action={'git.manage-gitignore'}>
            <TitledGreyBox title={'.gitignore'} css={tw`mt-4`}>
                <SpinnerOverlay visible={loading || saving} />
                <textarea
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    placeholder={'# Node\nnode_modules/\n.env'}
                    css={tw`w-full bg-neutral-800 border border-neutral-600 rounded p-3 text-sm text-neutral-100 min-h-[120px] font-mono`}
                />
                <div css={tw`flex justify-end mt-3`}>
                    <Button onClick={save} disabled={saving}>
                        Save
                    </Button>
                </div>
            </TitledGreyBox>
        </Can>
    );
};

const IdentityBox = ({ uuid }: { uuid: string }) => {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [saving, setSaving] = useState(false);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('git');

    useEffect(() => {
        getGitIdentity(uuid)
            .then((identity) => {
                setName(identity.name);
                setEmail(identity.email);
            })
            .catch((error) => clearAndAddHttpError(error));
    }, [uuid]);

    const save = () => {
        setSaving(true);
        clearFlashes();

        saveGitIdentity(uuid, name, email)
            .then(() => clearFlashes())
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setSaving(false));
    };

    return (
        <Can action={'git.manage-repository'}>
            <TitledGreyBox title={'Git Identity'} css={tw`mt-4`}>
                <SpinnerOverlay visible={saving} />
                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                    <div>
                        <Label>Name</Label>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={'Committer name'} />
                    </div>
                    <div>
                        <Label>Email</Label>
                        <Input value={email === '' ? undefined : email} onChange={(e) => setEmail(e.target.value)} placeholder={'name@example.com'} />
                    </div>
                </div>
                <div css={tw`flex justify-end mt-3`}>
                    <Button onClick={save} disabled={saving}>
                        Save
                    </Button>
                </div>
            </TitledGreyBox>
        </Can>
    );
};

interface ConnectRepositoryDialogProps {
    visible: boolean;
    onClose: () => void;
    onConnected: () => void;
    moduleEnabled: boolean;
}

const ConnectRepositoryDialog = ({ visible, onClose, onConnected, moduleEnabled }: ConnectRepositoryDialogProps) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('git');
    const [accounts, setAccounts] = useState<GithubAccount[]>([]);
    const [accountId, setAccountId] = useState<number | null>(null);
    const [query, setQuery] = useState('');
    const [repos, setRepos] = useState<GithubRepository[]>([]);
    const [repo, setRepo] = useState<GithubRepository | null>(null);
    const [mode, setMode] = useState<'clone' | 'pull'>('clone');
    const [busy, setBusy] = useState(false);
    const [browse, setBrowse] = useState(false);

    useEffect(() => {
        if (!visible) {
            return;
        }

        clearFlashes();

        getGithubAccounts()
            .then((list) => {
                setAccounts(list.accounts);
                setAccountId(list.accounts[0]?.id ?? null);

                if (list.accounts.length > 0) {
                    searchGithubRepositories(list.accounts[0].id).then(setRepos);
                }
            })
            .catch((error) => clearAndAddHttpError(error));
    }, [visible]);

    const loadRepos = (id: number, q = query) => {
        setBusy(true);

        searchGithubRepositories(id, q)
            .then(setRepos)
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(false));
    };

    const submit = () => {
        if (!accountId || !repo) {
            return;
        }

        setBusy(true);
        clearFlashes();

        connectRepository(uuid, {
            account_id: accountId,
            repository_id: String(repo.id),
            repository_full_name: repo.full_name,
            remote_url: repo.clone_url,
            default_branch: repo.default_branch,
            branch: repo.default_branch,
            mode,
        })
            .then(() => {
                onClose();
                onConnected();
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(false));
    };

    return (
        <Dialog open={visible} onClose={onClose} title={'Connect Repository'}>
            <SpinnerOverlay visible={busy} />

            {accounts.length === 0 ? (
                <div css={tw`text-center py-4`}>
                    <FontAwesomeIcon icon={faGithub} size={'2x'} css={tw`mb-3 text-neutral-400`} />
                    <p css={tw`text-sm text-neutral-300 mb-4`}>
                        No GitHub accounts connected yet. Link one from your account page to get started.
                    </p>
                    <a
                        href={'/account/github'}
                        css={tw`inline-flex items-center gap-2 px-4 py-2 bg-neutral-700 hover:bg-neutral-600 text-white text-sm rounded transition-colors`}
                    >
                        <FontAwesomeIcon icon={faGithub} />
                        Connect GitHub Account
                    </a>
                </div>
            ) : (
                <>
                    <div css={tw`mb-4`}>
                        <Label>GitHub Account</Label>
                        <select
                            value={accountId ?? ''}
                            onChange={(e) => {
                                const id = Number(e.target.value);
                                setAccountId(id);
                                setRepo(null);
                                loadRepos(id);
                            }}
                            css={tw`w-full bg-neutral-800 border border-neutral-600 rounded p-2 text-sm text-neutral-100`}
                        >
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.username}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div css={tw`mb-4`}>
                        <Label>Repository</Label>
                        <div css={tw`flex gap-2`}>
                            <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder={'Search repositories…'} css={tw`flex-1`} />
                            <Button variant={Button.Variants.Secondary} onClick={() => accountId && loadRepos(accountId)}>
                                {browse ? 'Search' : 'Refresh'}
                            </Button>
                            <Button variant={Button.Variants.Secondary} onClick={() => setBrowse((b) => !b)}>
                                {browse ? 'List' : 'Browse'}
                            </Button>
                        </div>
                        {browse && (
                            <div css={tw`mt-2 text-xs text-neutral-500`}>
                                Or type the full name (e.g. <code>octocat/Hello-World</code>) and press Refresh.
                            </div>
                        )}
                        <div css={tw`mt-2 max-h-56 overflow-y-auto divide-y divide-neutral-700 border border-neutral-700 rounded`}>
                            {repos.map((r) => (
                                <button
                                    key={r.id}
                                    onClick={() => setRepo(r)}
                                    css={[
                                        tw`w-full text-left px-3 py-2 text-sm hover:bg-neutral-700`,
                                        repo?.id === r.id && tw`bg-neutral-700`,
                                    ]}
                                >
                                    <span css={tw`text-neutral-100`}>{r.full_name}</span>
                                    <span css={tw`ml-2 text-xs text-neutral-500`}>
                                        {r.private ? 'private' : 'public'} · {r.default_branch}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>

                    {repo && (
                        <>
                            <div css={tw`mb-4`}>
                                <Label>Deployment Behaviour</Label>
                                <div css={tw`flex gap-4 text-sm text-neutral-300`}>
                                    <label css={tw`flex items-center`}>
                                        <input type={'radio'} checked={mode === 'clone'} onChange={() => setMode('clone')} css={tw`mr-2`} />
                                        Replace contents with remote
                                    </label>
                                    <label css={tw`flex items-center`}>
                                        <input type={'radio'} checked={mode === 'pull'} onChange={() => setMode('pull')} css={tw`mr-2`} />
                                        Keep existing files
                                    </label>
                                </div>
                            </div>

                            <div css={tw`flex justify-end`}>
                                <Button onClick={submit} disabled={busy}>
                                    Connect
                                </Button>
                            </div>
                        </>
                    )}
                </>
            )}
        </Dialog>
    );
};