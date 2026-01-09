// assets/js/thread-data.js
// Data / API layer for thread posts

(function (ns) {
  const state = ns.state;
  const recLabel = ns.recLabel;

  /**
   * Upsert an array of posts into state.posts.
   * Returns the list of posts that were newly added.
   */
  function upsertPosts(newPosts) {
    const added = [];

    newPosts.forEach(p => {
      const idNum = Number(p.id);
      if (!idNum) return;

      const existingIdx = state.posts.findIndex(existing => Number(existing.id) === idNum);

      if (existingIdx === -1) {
        state.posts.push(p);
        added.push(p);
      } else {
        state.posts[existingIdx] = p;
      }

      if (idNum > state.lastPostId && Number(p.is_deleted) !== 1) {
        state.lastPostId = idNum;
      }
    });

    return added;
  }

  /**
   * Fetch posts from server.
   *  - initial=true: uses limit/offset paging
   *  - initial=false: uses since_id for lightweight polling
   *
   * Returns { added, data }.
   */
  async function fetchPosts({ initial = false } = {}) {
  let url = `/api/post_api_posts_list.php?thread_id=${state.THREAD_ID}`;

  if (initial) {
    url += `&limit=${state.PAGE_SIZE}&offset=${state.offset}`;
  } else {
    url += `&since_id=${state.lastPostId}`;
  }

  try {
    const res = await fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    if (!res.ok) {
      console.error('fetchPosts HTTP error', res.status);
      return { added: [], data: [] };
    }

    const text = await res.text();

    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      console.error('fetchPosts: invalid JSON, raw response:', text);
      return { added: [], data: [] };
    }

    if (!Array.isArray(data) || data.length === 0) {
      return { added: [], data: [] };
    }

    const added = upsertPosts(data);

    if (initial && data.length === state.PAGE_SIZE) {
      state.offset += state.PAGE_SIZE;
    }

    return { added, data };
  } catch (err) {
    console.error('fetchPosts error', err);
    return { added: [], data: [] };
  }
}
  /**
   * Submit a new post.
   * Expects { threadId, parentId, bodyHtml }.
   * Returns the new post (or null) and upserts it into state.
   */
  async function submitPost({ threadId, parentId, bodyHtml }) {
    const res = await fetch('/api/post_api_post_create.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        thread_id: threadId,
        parent_id: parentId,
        body_html: bodyHtml
      })
    });

    if (!res.ok) {
      console.error('Post failed');
      return null;
    }

    const newPost = await res.json();
    if (newPost && newPost.id) {
      newPost.rec_count = newPost.rec_count || 0;
      newPost.has_rec   = newPost.has_rec   || 0;

      upsertPosts([newPost]);
      return newPost;
    }

    return null;
  }

  /**
   * Delete a post (and its children) on the server and in local state.
   * Returns array of deleted ids.
   */
  async function deletePost(postId) {
    const res = await fetch('/api/post_api_post_delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ post_id: postId })
    });

    if (!res.ok) {
      console.error('Delete failed (HTTP)', res.status);
      return [];
    }

    const data = await res.json();
    if (!data || !data.ok) {
      console.error('Delete failed (API)', data && data.error);
      return [];
    }

    const deletedIds = Array.isArray(data.deleted_ids)
      ? data.deleted_ids.map(Number)
      : [Number(data.deleted_id || postId)];

    const deletedSet = new Set(deletedIds.filter(Boolean));

    state.posts = state.posts.map(p => {
      if (deletedSet.has(Number(p.id))) {
        return { ...p, is_deleted: 1 };
      }
      return p;
    });

    return deletedIds;
  }

  /**
   * Toggle rec for a post.
   * Still wired directly to the button element for simplicity.
   */
  async function toggleRec(post, postEl, recBtn) {
    try {
      const res = await fetch('/api/post_api_post_toggle_rec.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: 'post_id=' + encodeURIComponent(post.id)
      });

      if (!res.ok) {
        console.error('Rec toggle failed');
        return;
      }

      const data = await res.json();
      if (!data || !data.ok) return;

      const count = parseInt(data.rec_count, 10) || 0;
      recBtn.textContent = recLabel(count);
      post.rec_count = count;

      if (count > 0) {
        postEl.classList.add('post--rec');
      } else {
        postEl.classList.remove('post--rec');
      }
    } catch (err) {
      console.error('toggleRec error', err);
    }
  }

  // Expose on namespace
  ns.upsertPosts   = upsertPosts;
  ns.fetchPosts    = fetchPosts;
  ns.submitPost    = submitPost;
  ns.deletePostAPI = deletePost; // name avoids clashing with UI handler
  ns.toggleRec     = toggleRec;
})(window.GAMEDAY_THREAD);
