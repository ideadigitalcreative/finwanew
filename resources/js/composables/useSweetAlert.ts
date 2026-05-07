import Swal from 'sweetalert2';
import type { SweetAlertOptions as SwalOptions } from 'sweetalert2';

export type SweetAlertOptions = Partial<SwalOptions> & {
    title?: string;
    text?: string;
    html?: string;
    icon?: 'success' | 'error' | 'warning' | 'info' | 'question';
    confirmButtonText?: string;
    cancelButtonText?: string;
    showCancelButton?: boolean;
    showDenyButton?: boolean;
    denyButtonText?: string;
    confirmButtonColor?: string;
    cancelButtonColor?: string;
    denyButtonColor?: string;
    timer?: number;
    timerProgressBar?: boolean;
    allowOutsideClick?: boolean;
    allowEscapeKey?: boolean;
    allowEnterKey?: boolean;
    showLoaderOnConfirm?: boolean;
    preConfirm?: () => Promise<any>;
    input?: 'text' | 'email' | 'password' | 'number' | 'tel' | 'range' | 'textarea' | 'select' | 'radio' | 'checkbox' | 'file' | 'url';
    inputValue?: string;
    inputPlaceholder?: string;
    inputValidator?: (value: string) => string | null;
    width?: string | number;
    padding?: string | number;
    backdrop?: boolean;
    backdropClass?: string;
    color?: string;
    background?: string;
    customClass?: {
        container?: string;
        popup?: string;
        title?: string;
        closeButton?: string;
        icon?: string;
        image?: string;
        htmlContainer?: string;
        input?: string;
        inputLabel?: string;
        validationMessage?: string;
        actions?: string;
        confirmButton?: string;
        denyButton?: string;
        cancelButton?: string;
        loader?: string;
        footer?: string;
        timerProgressBar?: string;
    };
}

const defaultOptions: SweetAlertOptions = {
    confirmButtonText: 'OK',
    cancelButtonText: 'Batal',
    denyButtonText: 'Tidak',
    confirmButtonColor: '#3b82f6',
    cancelButtonColor: '#6b7280',
    denyButtonColor: '#ef4444',
    allowOutsideClick: true,
    allowEscapeKey: true,
    allowEnterKey: true,
    width: '28rem',
    padding: '1.25rem',
    backdrop: true,
    customClass: {
        popup: 'rounded-3xl bg-white border border-gray-200 shadow-xl',
        title: 'text-base font-semibold text-gray-800',
        htmlContainer: 'text-sm text-gray-700',
        confirmButton: 'px-4 py-2 rounded-2xl font-medium text-white',
        cancelButton: 'px-4 py-2 rounded-2xl font-medium text-white',
        denyButton: 'px-4 py-2 rounded-2xl font-medium text-white',
        closeButton: 'text-gray-400 hover:text-gray-600',
    },
    color: '#1f2937',
    background: '#ffffff',
};

export function useSweetAlert() {
    const showAlert = (options: SweetAlertOptions | string) => {
        if (typeof options === 'string') {
            return Swal.fire({
                ...defaultOptions,
                title: options,
                icon: 'info',
            } as SwalOptions);
        }
        return Swal.fire({
            ...defaultOptions,
            ...options,
        } as SwalOptions);
    };

    const showSuccess = (title: string, text?: string) => {
        return Swal.fire({
            ...defaultOptions,
            title,
            html: text,
            icon: 'success',
        } as SwalOptions);
    };

    const showError = (title: string, text?: string) => {
        return Swal.fire({
            ...defaultOptions,
            title,
            html: text,
            icon: 'error',
        } as SwalOptions);
    };

    const showWarning = (title: string, text?: string) => {
        return Swal.fire({
            ...defaultOptions,
            title,
            html: text,
            icon: 'warning',
        } as SwalOptions);
    };

    const showInfo = (title: string, text?: string) => {
        return Swal.fire({
            ...defaultOptions,
            title,
            html: text,
            icon: 'info',
        } as SwalOptions);
    };

    const showQuestion = (title: string, text?: string) => {
        return Swal.fire({
            ...defaultOptions,
            title,
            html: text,
            icon: 'question',
        } as SwalOptions);
    };

    const showConfirm = async (
        title: string,
        text?: string,
        options?: Partial<SweetAlertOptions>
    ): Promise<boolean> => {
        const result = await Swal.fire({
            ...defaultOptions,
            title,
            text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: options?.confirmButtonText || 'Ya',
            cancelButtonText: options?.cancelButtonText || 'Batal',
            ...options,
        } as SwalOptions);

        return result.isConfirmed;
    };

    const showDeleteConfirm = async (
        title: string = 'Hapus?',
        text: string = 'Tindakan ini tidak dapat dibatalkan!'
    ): Promise<boolean> => {
        const result = await Swal.fire({
            ...defaultOptions,
            title,
            text,
            icon: 'warning',
            iconColor: '#f59e0b',
            showCancelButton: true,
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            reverseButtons: true,
        } as SwalOptions);

        return result.isConfirmed;
    };

    const showLoading = (title: string = 'Memproses...', text?: string) => {
        Swal.fire({
            ...defaultOptions,
            title,
            text,
            allowOutsideClick: false,
            allowEscapeKey: false,
            allowEnterKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            },
        } as SwalOptions);
    };

    const close = () => {
        Swal.close();
    };

    const showToast = (
        title: string,
        icon: 'success' | 'error' | 'warning' | 'info' = 'success',
        timer: number = 3000
    ) => {
        return Swal.fire({
            title,
            icon,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer,
            timerProgressBar: true,
            background: '#ffffff',
            color: '#1f2937',
            customClass: {
                popup: 'rounded-3xl bg-white border border-gray-200 shadow-lg',
                title: 'text-sm text-gray-800',
            },
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            },
        });
    };

    return {
        showAlert,
        showSuccess,
        showError,
        showWarning,
        showInfo,
        showQuestion,
        showConfirm,
        showDeleteConfirm,
        showLoading,
        close,
        showToast,
        Swal, // Export Swal instance for advanced usage
    };
}
