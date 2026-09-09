import http from '@/api/http';

export interface GithubAccount {
    id: number;
    provider: string;
    github_user_id: string;
    username: string;
    avatar_url?: string | null;
    linked_at: string;
    has_token: boolean;
}

export interface GithubModuleState {
    enabled: boolean;
    oauth_enabled: boolean;
    admin: boolean;
    base_url: string;
    accounts: GithubAccount[];
}

export interface GithubRepository {
    id: number;
    full_name: string;
    default_branch: string;
    clone_url: string;
    private: boolean;
    description?: string | null;
}

export const getGithubAccounts = async (): Promise<GithubModuleState> => {
    const { data } = await http.get('/api/client/account/github');

    return data;
};

export const connectGithubAccount = async (token: string): Promise<GithubAccount> => {
    const { data } = await http.post('/api/client/account/github', { token });

    return data;
};

export const deleteGithubAccount = (id: number) => http.delete(`/api/client/account/github/${id}`);

export const searchGithubRepositories = async (accountId: number, query = ''): Promise<GithubRepository[]> => {
    const { data } = await http.get('/api/client/account/github/repositories', {
        params: { account_id: accountId, q: query },
    });

    return data;
};