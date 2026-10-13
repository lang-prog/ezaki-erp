import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.interceptors.response.use((response) => response, (error) => {
	const status = error.response?.status;
	const payload = error.response?.data;
	const validation = payload?.errors ? Object.values(payload.errors).flat().join(' ') : '';
	window.dispatchEvent(new CustomEvent('ezaki:request-error', {
		detail: { status, message: validation || (status === 422 ? payload?.message : undefined) },
	}));
	return Promise.reject(error);
});
