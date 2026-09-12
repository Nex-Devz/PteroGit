import React, { useEffect, useState } from 'react';
import { useLocation, useHistory } from 'react-router-dom';
import tw from 'twin.macro';
import { faTrashAlt, faPlus, faShieldAlt, faKey } from '@fortawesome/free-solid-svg-icons';
import { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

const faGithub: IconDefinition = {
    prefix: 'fab',
    iconName: 'github' as any,
    icon: [
        496,
        512,
        [],
        'f092',
        'M165.9 397.4c0 2-2.3 3.6-5.2 3.6-3.3.3-5.6-1.3-5.6-3.6 0-2 2.3-3.6 5.2-3.6 3-.3 5.6 1.3 5.6 3.6zm-31.1-4.5c-.7 2 1.3 4.3 4.3 4.9 2.6 1 5.6 0 6.2-2s-1.3-4.3-4.3-5.2c-2.6-.7-5.5.3-6.2 2.3zm44.2-1.7c-2.9.7-4.9 2.6-4.6 4.9.3 2 2.9 3.3 5.9 2.6 2.9-.7 4.9-2.6 4.6-4.6-.3-1.9-3-3.2-5.9-2.9zM244.8 8C106.1 8 0 113.3 0 252c0 110.9 69.8 205.8 169.5 239.2 12.8 2.3 17.3-5.6 17.3-12.1 0-6.2-.3-40.4-.3-61.4 0 0-70 15-84.7-29.8 0 0-11.4-29.1-27.8-36.6 0 0-22.9-15.7 1.6-15.4 0 0 24.9 2 38.6 25.8 21.9 38.6 58.6 27.5 72.9 20.9 2.3-16 8.8-27.1 16-33.7-55.9-6.2-112.3-14.3-112.3-110.5 0-27.5 7.6-41.3 23.6-58.9-2.6-6.5-11.1-33.3 2.6-67.9 20.9-6.5 69 27 69 27 20-5.6 41.5-8.5 62.8-8.5s42.8 2.9 62.8 8.5c0 0 48.1-33.6 69-27 13.7 34.7 5.2 61.4 2.6 67.9 16 17.7 25.8 31.5 25.8 58.9 0 96.5-58.9 104.2-114.8 110.5 9.2 7.9 17 22.9 17 46.4 0 33.7-.3 75.4-.3 83.6 0 6.5 4.6 14.4 17.3 12.1C428.2 457.8 496 362.9 496 252 496 113.3 383.5 8 244.8 8zM97.2 352.9c-1.3 1-1 3.3.7 5.2 1.6 1.6 3.9 2.3 5.2 1 1.3-1 1-3.3-.7-5.2-1.6-1.6-3.9-2.3-5.2-1zm-10.8-8.1c-.7 1.3.3 2.9 2.3 3.9 1.6 1 3.6.7 4.3-.7.7-1.3-.3-2.9-2.3-3.9-2-.6-3.6-.3-4.3.7zm32.4 35.6c-1.6 1.3-1 4.3 1.3 6.2 2.3 2.3 5.2 2.6 6.5 1 1.3-1.3.7-4.3-1.3-6.2-2.2-2.3-5.2-2.6-6.5-1zm-11.4-14.7c-1.6 1-1.6 3.6 0 5.9 1.6 2.3 4.3 3.3 5.6 2.3 1.6-1.3 1.6-3.9 0-6.2-1.4-2.3-4-3.3-5.6-2z',
    ],
};
import ContentBox from '@/components/elements/ContentBox';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import FlashMessageRender from '@/components/FlashMessageRender';
import PageContentBlock from '@/components/elements/PageContentBlock';
import GreyRowBox from '@/components/elements/GreyRowBox';
import { Button } from '@/components/elements/button';
import Input from '@/components/elements/Input';
import Label from '@/components/elements/Label';
import { Dialog } from '@/components/elements/dialog';
import useFlash, { useFlashKey } from '@/plugins/useFlash';
import { format } from 'date-fns';
import {
    deleteGithubAccount,
    getGithubAccounts,
    connectGithubAccount,
    GithubAccount,
    GithubModuleState,
} from '@/api/account/github';

export default () => {
    const location = useLocation();
    const history = useHistory();
    const { addFlash } = useFlash();

    const [moduleState, setModuleState] = useState<GithubModuleState | null>(null);
    const [accounts, setAccounts] = useState<GithubAccount[]>([]);
    const [loading, setLoading] = useState(true);
    const [connectVisible, setConnectVisible] = useState(false);
    const [deleteAccount, setDeleteAccount] = useState<GithubAccount | null>(null);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('github');
    const [authError, setAuthError] = useState(false);

    const refresh = () =>
        getGithubAccounts()
            .then((state) => {
                setModuleState(state);
                setAccounts(state.accounts);
                setAuthError(false);
            })
            .catch((error) => {
                if (error?.response?.status === 401) {
                    setAuthError(true);
                } else {
                    clearAndAddHttpError(error);
                }
            })
            .then(() => setLoading(false));

    useEffect(() => {
        const params = new URLSearchParams(location.search);
        const oauthStatus = params.get('oauth');

        if (oauthStatus === 'connected') {
            addFlash({
                key: 'github',
                type: 'success',
                message: 'Successfully linked your GitHub account via OAuth.',
            });
            history.replace(location.pathname);
        } else if (oauthStatus === 'error') {
            const msg = params.get('message') || 'An error occurred during GitHub authorization.';
            addFlash({
                key: 'github',
                type: 'error',
                message: msg,
            });
            history.replace(location.pathname);
        }

        refresh();
    }, []);

    const submitConnect = (token: string) => {
        if (!token.trim()) {
            return;
        }

        setLoading(true);
        clearFlashes();

        connectGithubAccount(token.trim())
            .then(() => {
                setConnectVisible(false);
                refresh();
            })
            .catch((error) => {
                if (error?.response?.status === 401) {
                    setAuthError(true);
                } else {
                    clearAndAddHttpError(error);
                }
                setLoading(false);
            });
    };

    const doDelete = () => {
        if (!deleteAccount) {
            return;
        }

        setLoading(true);
        clearFlashes();

        deleteGithubAccount(deleteAccount.id)
            .then(() => refresh())
            .catch((error) => {
                if (error?.response?.status === 401) {
                    setAuthError(true);
                } else {
                    clearAndAddHttpError(error);
                }
                setLoading(false);
            })
            .then(() => setDeleteAccount(null));
    };

    const disabled = moduleState && !moduleState.enabled && !moduleState.admin;

    return (
        <PageContentBlock title={'GitHub'}>
            <FlashMessageRender byKey={'github'} css={tw`mb-4`} />
            {authError && !loading && (
                <ContentBox title={'Login Required'}>
                    <div css={tw`text-center p-4`}>
                        <FontAwesomeIcon icon={faGithub} size={'2x'} css={tw`mb-3 text-neutral-400`} />
                        <p css={tw`text-sm text-neutral-300 mb-4`}>
                            Log in to your panel account to manage GitHub connections.
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
            {!authError && disabled && (
                <ContentBox title={'Module Disabled'}>
                    <div css={tw`text-center p-4`}>
                        <FontAwesomeIcon icon={faShieldAlt} size={'2x'} css={tw`mb-3 text-neutral-400`} />
                        <p css={tw`text-sm text-neutral-400`}>
                            The GitHub integration is currently disabled by an administrator.
                        </p>
                    </div>
                </ContentBox>
            )}
            {!authError && !disabled && (
                <ContentBox title={'GitHub Connections'}>
                    <SpinnerOverlay visible={loading} />

                    {moduleState && !moduleState.enabled && moduleState.admin && !loading && (
                        <div css={tw`mb-4 p-3 bg-yellow-100 border border-yellow-300 text-sm text-yellow-800 rounded`}>
                            The GitHub module is currently disabled. Only administrators can view existing connections while maintenance is in progress.
                        </div>
                    )}

                    {accounts.length === 0 && !loading ? (
                        <p css={tw`text-center text-sm text-neutral-300 p-4`}>
                            Connect your GitHub account with OAuth or a personal access token to enable Git integration on your
                            servers.
                        </p>
                    ) : (
                        accounts.map((account) => (
                            <GreyRowBox key={account.id} css={tw`flex items-center`}>
                                {account.avatar_url ? (
                                    <img src={account.avatar_url} css={tw`w-10 h-10 rounded-full mr-4`} />
                                ) : (
                                    <FontAwesomeIcon icon={faGithub} size={'2x'} css={tw`mr-4 text-neutral-400`} />
                                )}
                                <div css={tw`flex-1`}>
                                    <p css={tw`text-sm font-medium`}>{account.username}</p>
                                    <p css={tw`text-xs text-neutral-400`}>
                                        Linked {format(new Date(account.linked_at), 'MMM d, yyyy')}
                                    </p>
                                </div>
                                <Button.Danger
                                    variant={Button.Variants.Secondary}
                                    onClick={() => setDeleteAccount(account)}
                                    css={tw`ml-4`}
                                >
                                    <FontAwesomeIcon icon={faTrashAlt} />
                                </Button.Danger>
                            </GreyRowBox>
                        ))
                    )}

                    <div css={tw`mt-6 flex items-center justify-end`}>
                        <Button onClick={() => setConnectVisible(true)} disabled={moduleState && !moduleState.enabled}>
                            <FontAwesomeIcon icon={faPlus} css={tw`mr-2`} />
                            Connect Account
                        </Button>
                    </div>
                </ContentBox>
            )}

            {!authError && !disabled && (
                <Dialog open={connectVisible} onClose={() => setConnectVisible(false)} title={'Connect GitHub Account'}>
                    <ConnectDialogContent
                        oauthEnabled={!!moduleState?.oauth_enabled}
                        onConnectToken={submitConnect}
                        loading={loading}
                    />
                </Dialog>
            )}

            <Dialog.Confirm
                open={!!deleteAccount}
                title={'Remove GitHub Account'}
                confirm={'Remove'}
                onClose={() => setDeleteAccount(null)}
                onConfirmed={doDelete}
            >
                {deleteAccount && `Are you sure you want to remove your connection ${deleteAccount.username}?`}
            </Dialog.Confirm>
        </PageContentBlock>
    );
};

const ConnectDialogContent = ({
    oauthEnabled,
    onConnectToken,
    loading,
}: {
    oauthEnabled: boolean;
    onConnectToken: (token: string) => void;
    loading: boolean;
}) => {
    const [method, setMethod] = useState<'oauth' | 'pat'>(oauthEnabled ? 'oauth' : 'pat');
    const [token, setToken] = useState('');

    return (
        <div>
            <div css={tw`flex border-b border-neutral-700 mb-4 pb-2 gap-3`}>
                <button
                    type={'button'}
                    onClick={() => setMethod('oauth')}
                    css={[
                        tw`px-3 py-1.5 text-sm font-medium rounded transition-colors`,
                        method === 'oauth'
                            ? tw`bg-cyan-600 text-white`
                            : tw`text-neutral-400 hover:text-white bg-neutral-800`,
                    ]}
                >
                    <FontAwesomeIcon icon={faGithub} css={tw`mr-2`} />
                    OAuth2 Sign-In
                </button>
                <button
                    type={'button'}
                    onClick={() => setMethod('pat')}
                    css={[
                        tw`px-3 py-1.5 text-sm font-medium rounded transition-colors`,
                        method === 'pat'
                            ? tw`bg-cyan-600 text-white`
                            : tw`text-neutral-400 hover:text-white bg-neutral-800`,
                    ]}
                >
                    <FontAwesomeIcon icon={faKey} css={tw`mr-2`} />
                    Personal Access Token
                </button>
            </div>

            {method === 'oauth' ? (
                <div>
                    {oauthEnabled ? (
                        <div css={tw`text-center py-4`}>
                            <p css={tw`text-sm text-neutral-300 mb-4`}>
                                Authorize this panel with your GitHub account in one click. You will be redirected to GitHub to approve access with the <code>repo</code> scope.
                            </p>
                            <a
                                href={'/account/github/oauth/begin'}
                                css={tw`inline-flex items-center justify-center px-4 py-2.5 bg-neutral-900 hover:bg-neutral-800 text-white font-medium rounded text-sm transition-colors border border-neutral-600 shadow`}
                            >
                                <FontAwesomeIcon icon={faGithub} css={tw`mr-2 text-base`} />
                                Continue with GitHub
                            </a>
                        </div>
                    ) : (
                        <div css={tw`p-3 bg-neutral-800 border border-neutral-700 rounded text-sm text-neutral-400 text-center`}>
                            <p css={tw`mb-2`}>
                                GitHub OAuth2 is not currently configured or enabled on this panel.
                            </p>
                            <p css={tw`text-xs text-neutral-500`}>
                                Please switch to the <strong>Personal Access Token</strong> tab to link your account manually.
                            </p>
                        </div>
                    )}
                </div>
            ) : (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        onConnectToken(token);
                    }}
                >
                    <Label>Personal Access Token</Label>
                    <Input
                        type={'password'}
                        value={token}
                        onChange={(e) => setToken(e.target.value)}
                        placeholder={'ghp_xxxxxxxxxxxxxxxxxxxx'}
                        autoFocus
                    />
                    <div css={tw`text-xs text-neutral-500 mt-2`}>
                        Create a token with <code>repo</code> scope at{' '}
                        <a href={'https://github.com/settings/tokens'} target={'_blank'} rel={'noreferrer'} css={tw`text-cyan-400 underline`}>
                            github.com/settings/tokens
                        </a>
                        . Tokens are encrypted and never shown again.
                    </div>
                    <div css={tw`flex flex-wrap items-center justify-end mt-6`}>
                        <Button type={'submit'} disabled={loading || !token.trim()}>
                            Verify &amp; Connect
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
};