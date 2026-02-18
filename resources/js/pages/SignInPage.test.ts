import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/auth';
import SignInPage from './SignInPage.vue';

let pinia: ReturnType<typeof createPinia>;

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
});

describe('signInPage', () => {
    it('renders the Vunnix logo', () => {
        const wrapper = mount(SignInPage, {
            global: { plugins: [pinia] },
        });

        const img = wrapper.find('img[alt="Vunnix"]');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBeTruthy();
    });

    it('renders the tagline', () => {
        const wrapper = mount(SignInPage, {
            global: { plugins: [pinia] },
        });

        expect(wrapper.text()).toContain('AI First for GitLab');
    });

    it('renders the sign-in button with GitLab text', () => {
        const wrapper = mount(SignInPage, {
            global: { plugins: [pinia] },
        });

        const button = wrapper.find('button');
        expect(button.exists()).toBe(true);
        expect(button.text()).toContain('Sign in with GitLab');
    });

    it('renders the GitLab tanuki SVG icon', () => {
        const wrapper = mount(SignInPage, {
            global: { plugins: [pinia] },
        });

        const svg = wrapper.find('button svg');
        expect(svg.exists()).toBe(true);
    });

    it('calls auth.login() when sign-in button is clicked', async () => {
        const wrapper = mount(SignInPage, {
            global: { plugins: [pinia] },
        });

        const auth = useAuthStore();
        const loginSpy = vi.spyOn(auth, 'login').mockImplementation(() => {});

        await wrapper.find('button').trigger('click');

        expect(loginSpy).toHaveBeenCalledOnce();
    });
});
