/**
 * woquan EventBus — 轻量级事件总线（零依赖）
 *
 * 用途：解耦前端模块间的隐式 this 依赖，将"状态变化"转化为订阅/发布模式
 *
 * 设计原则：
 * 1. 零依赖，纯 ES Module
 * 2. 支持 on / off / once / emit / clear
 * 3. 支持通配符 '*' 监听所有事件（仅调试用）
 * 4. emit 同步执行，handler 异常不影响后续订阅者
 * 5. 订阅返回 unsubscribe 函数，方便模块销毁时清理
 *
 * 约定的事件名命名空间（kebab-case）：
 *   - auth:login / auth:logout / auth:token-refreshed
 *   - unread:notification-changed / unread:chat-changed
 *   - timeline:new-post / timeline:post-updated / timeline:post-deleted
 *   - ui:theme-changed / ui:lang-changed
 *
 * 用法：
 *   import { bus } from './modules/events-bus.js';
 *   const off = bus.on('auth:login', (user) => { ... });
 *   bus.emit('auth:login', user);
 *   off(); // 取消订阅
 */

const handlers = new Map();

/**
 * 订阅事件
 * @param {string} event 事件名（或 '*' 监听所有事件）
 * @param {Function} handler 处理函数
 * @returns {Function} unsubscribe 调用即可取消订阅
 */
export function on(event, handler) {
    if (typeof event !== 'string' || typeof handler !== 'function') {
        console.warn('[EventBus] on(event, handler) 参数类型错误', event, handler);
        return () => {};
    }
    if (!handlers.has(event)) {
        handlers.set(event, new Set());
    }
    handlers.get(event).add(handler);
    return () => off(event, handler);
}

/**
 * 取消订阅
 * @param {string} event
 * @param {Function} handler
 */
export function off(event, handler) {
    const set = handlers.get(event);
    if (!set) return;
    if (handler === undefined) {
        handlers.delete(event);
        return;
    }
    set.delete(handler);
    if (set.size === 0) {
        handlers.delete(event);
    }
}

/**
 * 只订阅一次
 * @param {string} event
 * @param {Function} handler
 * @returns {Function} unsubscribe
 */
export function once(event, handler) {
    if (typeof event !== 'string' || typeof handler !== 'function') {
        console.warn('[EventBus] once(event, handler) 参数类型错误', event, handler);
        return () => {};
    }
    const wrapper = (...args) => {
        off(event, wrapper);
        try { handler(...args); } catch (e) { console.error('[EventBus] once handler 异常:', e); }
    };
    return on(event, wrapper);
}

/**
 * 发布事件
 * 同步执行所有订阅者；单个订阅者异常被捕获并打印，不影响后续订阅者
 * @param {string} event
 * @param {...any} args 传递给订阅者的参数
 */
export function emit(event, ...args) {
    // 1. 精确匹配的订阅者
    const set = handlers.get(event);
    if (set) {
        // 拷贝一份，防止 handler 内部 off 导致迭代异常
        for (const handler of [...set]) {
            try {
                handler(...args);
            } catch (e) {
                console.error(`[EventBus] handler 异常 (${event}):`, e);
            }
        }
    }

    // 2. 通配符订阅者
    const wildcard = handlers.get('*');
    if (wildcard) {
        for (const handler of [...wildcard]) {
            try {
                handler(event, ...args);
            } catch (e) {
                console.error(`[EventBus] wildcard handler 异常 (${event}):`, e);
            }
        }
    }
}

/**
 * 清空所有订阅（主要用于单元测试和热重载场景）
 * 生产环境慎用
 */
export function clear() {
    handlers.clear();
}

/**
 * 获取当前订阅统计（调试用）
 * @returns {Object} { event: subscriberCount }
 */
export function stats() {
    const result = {};
    for (const [event, set] of handlers) {
        result[event] = set.size;
    }
    return result;
}

// 默认导出单例（全应用共享一个 bus 实例）
const bus = { on, off, once, emit, clear, stats };
export default bus;
export { bus };
