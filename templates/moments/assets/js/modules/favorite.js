/**
 * woquan 动态收藏
 */

export async function toggleFavorite(app, postId) {
    if (!app.checkAuth()) return;
    try {
        const data = await app.request('/favorites/toggle', {
            method: 'POST',
            body: { post_id: postId }
        });
        const postEl = document.querySelector(`.moment-item[data-id="${postId}"]`);
        if (postEl) {
            const btn = postEl.querySelector('.moment-act-fav');
            if (btn) {
                btn.classList.toggle('favorited', !!data.favorited);
            }
        }
        app.showToast(data.favorited ? '已收藏' : '已取消收藏');
        return data;
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function checkFavorite(app, postId) {
    // P0-3：登录态看 app.authed，不看 app.token
    if (app.authed === false || !postId) return false;
    try {
        const data = await app.request(`/favorites/check?post_id=${postId}`);
        return data?.favorited ?? false;
    } catch (e) {
        return false;
    }
}

export async function loadFavorites(app, page = 1) {
    try {
        return await app.request(`/favorites/list?page=${page}&limit=10`);
    } catch (e) {
        app.showToast(e.message);
        return { list: [], pagination: { pages: 0 } };
    }
}

export function buildFavoriteButton(app, post) {
    const favorited = post.favorited || false;
    return `<button class="action-favorite ${favorited ? 'favorited' : ''}" onclick="app.toggleFavorite(${post.id}); app.hideActionMenu(${post.id})">
        <svg viewBox="0 0 24 24" fill="${favorited ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
        <span>${favorited ? '已收藏' : '收藏'}</span>
    </button>`;
}
