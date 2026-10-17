import { Component } from 'react';

/** Last-resort render guard: shows a localized, recoverable message instead of unmounting the page. */
export default class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { failed: false };
    }

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error, info) {
        if (typeof this.props.onError === 'function') this.props.onError(error, info);
        else if (typeof console !== 'undefined') console.error('Render failure contained by ErrorBoundary:', error, info?.componentStack);
    }

    componentDidUpdate(previous) {
        if (this.state.failed && previous.resetKey !== this.props.resetKey) this.setState({ failed: false });
    }

    render() {
        if (!this.state.failed) return this.props.children;
        const retry = () => this.setState({ failed: false });

        return typeof this.props.fallback === 'function' ? this.props.fallback({ retry }) : this.props.fallback ?? null;
    }
}
