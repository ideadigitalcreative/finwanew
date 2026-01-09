/**
 * Facebook Pixel Tracking Composable
 * 
 * Digunakan untuk tracking event-event penting seperti:
 * - PageView (otomatis)
 * - CompleteRegistration
 * - Lead
 * - InitiateCheckout
 * - Purchase
 */

declare global {
    interface Window {
        fbq: (...args: any[]) => void;
    }
}

export function useFacebookPixel() {
    /**
     * Check if Facebook Pixel is available
     */
    const isAvailable = (): boolean => {
        return typeof window !== 'undefined' && typeof window.fbq === 'function';
    };

    /**
     * Track custom event
     */
    const trackEvent = (eventName: string, params?: Record<string, any>) => {
        if (isAvailable()) {
            window.fbq('track', eventName, params);
        }
    };

    /**
     * Track custom event with custom data
     */
    const trackCustom = (eventName: string, params?: Record<string, any>) => {
        if (isAvailable()) {
            window.fbq('trackCustom', eventName, params);
        }
    };

    /**
     * Track page view
     */
    const trackPageView = () => {
        if (isAvailable()) {
            window.fbq('track', 'PageView');
        }
    };

    /**
     * Track when user completes registration
     */
    const trackCompleteRegistration = (params?: {
        content_name?: string;
        currency?: string;
        value?: number;
        status?: boolean;
    }) => {
        trackEvent('CompleteRegistration', params);
    };

    /**
     * Track when user becomes a lead (e.g., signs up for free trial)
     */
    const trackLead = (params?: {
        content_name?: string;
        currency?: string;
        value?: number;
    }) => {
        trackEvent('Lead', params);
    };

    /**
     * Track when user initiates checkout
     */
    const trackInitiateCheckout = (params?: {
        content_name?: string;
        content_ids?: string[];
        contents?: Array<{ id: string; quantity: number }>;
        currency?: string;
        num_items?: number;
        value?: number;
    }) => {
        trackEvent('InitiateCheckout', params);
    };

    /**
     * Track when user completes a purchase
     */
    const trackPurchase = (params: {
        content_name?: string;
        content_ids?: string[];
        contents?: Array<{ id: string; quantity: number }>;
        currency: string;
        num_items?: number;
        value: number;
    }) => {
        trackEvent('Purchase', params);
    };

    /**
     * Track when user adds to cart
     */
    const trackAddToCart = (params?: {
        content_name?: string;
        content_ids?: string[];
        content_type?: string;
        currency?: string;
        value?: number;
    }) => {
        trackEvent('AddToCart', params);
    };

    /**
     * Track when user views content
     */
    const trackViewContent = (params?: {
        content_name?: string;
        content_ids?: string[];
        content_type?: string;
        currency?: string;
        value?: number;
    }) => {
        trackEvent('ViewContent', params);
    };

    /**
     * Track when user subscribes
     */
    const trackSubscribe = (params?: {
        currency?: string;
        value?: number;
        predicted_ltv?: number;
    }) => {
        trackEvent('Subscribe', params);
    };

    /**
     * Track when user starts trial
     */
    const trackStartTrial = (params?: {
        currency?: string;
        value?: number;
        predicted_ltv?: number;
    }) => {
        trackEvent('StartTrial', params);
    };

    return {
        isAvailable,
        trackEvent,
        trackCustom,
        trackPageView,
        trackCompleteRegistration,
        trackLead,
        trackInitiateCheckout,
        trackPurchase,
        trackAddToCart,
        trackViewContent,
        trackSubscribe,
        trackStartTrial,
    };
}
