export interface User {
    id: string;
    name: string;
    email: string;
    phone: string | null;
    email_verified: boolean;
    status: string;
    roles: string[];
    permissions: string[];
    is_staff: boolean;
    created_at: string | null;
}
