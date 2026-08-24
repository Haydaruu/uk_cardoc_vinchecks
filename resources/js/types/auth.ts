export type User = {
    id: number;
    role: string;
    name: string;
    email: string;
    avatar: string | null;
    credits: number;
    is_premium: boolean;

    email_verified_at: string | null;
    created_at: string;
    updated_at: string; 

    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};
