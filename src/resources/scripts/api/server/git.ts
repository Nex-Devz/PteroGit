import http from '@/api/http';

export interface GitChange {
    file: string;
    status: string;
    index: string;
    working: string;
    staged: boolean;
}

export interface GitRepositoryInfo {
    repository_full_name: string;
    default_branch: string;
    current_branch: string;
    working_directory: string;
    account_username?: string;
    avatar_url?: string | null;
}

export interface GitStatus {
    is_repository: boolean;
    connected: boolean;
    current_branch: string | null;
    ahead: number;
    behind: number;
    is_dirty: boolean;
    changes: GitChange[];
    last_commit: string | null;
    last_commit_message: string | null;
    repository: GitRepositoryInfo | null;
}

export interface GitBranchList {
    current: string;
    local: string[];
    remote: string[];
}

export interface GitCommit {
    hash: string;
    short: string;
    author: string;
    email: string;
    subject: string;
    relative: string;
}

const toStatus = (data: any): GitStatus => ({
    is_repository: data.is_repository,
    connected: data.connected,
    current_branch: data.current_branch,
    ahead: data.ahead,
    behind: data.behind,
    is_dirty: data.is_dirty,
    changes: data.changes,
    last_commit: data.last_commit,
    last_commit_message: data.last_commit_message,
    repository: data.repository,
});

export const getGitStatus = (uuid: string) => http.get(`/api/client/servers/${uuid}/github`);

export const getGitStatusParsed = async (uuid: string): Promise<GitStatus> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github`);

    return toStatus(data.status);
};

export interface GitModuleState {
    enabled: boolean;
    admin: boolean;
}

export const getGitModuleState = async (uuid: string): Promise<GitModuleState> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github`);

    return data.module;
};

export const connectRepository = (
    uuid: string,
    payload: {
        account_id: number;
        repository_id: string;
        repository_full_name: string;
        remote_url: string;
        default_branch: string;
        branch: string;
        working_directory?: string;
        mode: 'clone' | 'pull';
    }
) => http.post(`/api/client/servers/${uuid}/github/connect`, payload);

export const disconnectRepository = (uuid: string) => http.post(`/api/client/servers/${uuid}/github/disconnect`, { confirm: true });

export const resetRepository = (uuid: string, branch: string) =>
    http.post(`/api/client/servers/${uuid}/github/reset`, { branch, confirm: true });

export const getGitChanges = async (uuid: string): Promise<GitChange[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github/changes`);

    return data.changes;
};

export const getGitDiff = async (uuid: string, file?: string, root?: string): Promise<string | null> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github/diff`, {
        params: { file, root },
    });

    return data.diff;
};

export const stageFiles = (uuid: string, files: string[]) => http.post(`/api/client/servers/${uuid}/github/stage`, { files });

export const unstageFiles = (uuid: string, files: string[]) => http.post(`/api/client/servers/${uuid}/github/unstage`, { files });

export const discardFiles = (uuid: string, files: string[]) => http.post(`/api/client/servers/${uuid}/github/discard`, { files });

export const commitChanges = (uuid: string, message: string, push: boolean, all = false) =>
    http.post(`/api/client/servers/${uuid}/github/commit`, { message, push, all });

export const pullRepository = (uuid: string) => http.post(`/api/client/servers/${uuid}/github/pull`);

export const pushRepository = (uuid: string) => http.post(`/api/client/servers/${uuid}/github/push`);

export const getBranches = async (uuid: string): Promise<GitBranchList> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github/branches`);

    return data.branches;
};

export const createBranch = (uuid: string, name: string, basedOn: string) =>
    http.post(`/api/client/servers/${uuid}/github/branches`, { name, based_on: basedOn });

export const switchBranch = (uuid: string, name: string) => http.post(`/api/client/servers/${uuid}/github/branches/switch`, { name });

export const deleteBranch = (uuid: string, name: string, force: boolean) =>
    http.delete(`/api/client/servers/${uuid}/github/branches/${encodeURIComponent(name)}`, { data: { force } });

export const getCommitHistory = async (uuid: string, limit = 25): Promise<GitCommit[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github/history`, { params: { limit } });

    return data.commits;
};

export const revertCommit = (uuid: string, sha: string) => http.post(`/api/client/servers/${uuid}/github/revert`, { sha });

export const getGitignore = async (uuid: string): Promise<string> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github/gitignore`);

    return data.content ?? '';
};

export const saveGitignore = (uuid: string, content: string) => http.put(`/api/client/servers/${uuid}/github/gitignore`, { content });

export const getGitIdentity = async (uuid: string): Promise<{ name: string; email: string }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github/identity`);

    return data.identity;
};

export const saveGitIdentity = (uuid: string, name: string, email: string) =>
    http.post(`/api/client/servers/${uuid}/github/identity`, { name, email });

export const getRepositoryRemote = async (uuid: string): Promise<{ name: string; url: string }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/github/remote`);

    return data.remote;
};