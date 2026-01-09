// assets/js/thread.js
document.addEventListener('DOMContentLoaded', () => {
  // Prevent double-initialization if the script is ever loaded twice
  if (window.__gamedayThreadInitialized) return;
  window.__gamedayThreadInitialized = true;

  const root = document.getElementById('thread-root');
  if (!root) return;

  const threadIdAttr = root.dataset.threadId;
  if (!threadIdAttr) return;

  const THREAD_ID = parseInt(threadIdAttr, 10);
  if (!THREAD_ID) return;

  const IS_ADMIN = root.dataset.isAdmin === '1';
  const CURRENT_USER_ID = parseInt(root.dataset.currentUserId || '0', 10) || 0;

  const ns    = window.GAMEDAY_THREAD || {};
  const state = ns.state;
  const dom   = ns.dom;
  const lazy  = ns.lazy;
  const seen  = ns.seen;
  const toast = ns.toast;

  const recLabel   = ns.recLabel;
  const fetchPosts = ns.fetchPosts;
  const submitPostAPI = ns.submitPost;
  const deletePostAPI = ns.deletePostAPI;
  const toggleRec  = ns.toggleRec;

  // Hydrate shared state
  state.THREAD_ID       = THREAD_ID;
  state.IS_ADMIN        = IS_ADMIN;
  state.CURRENT_USER_ID = CURRENT_USER_ID;

  /* ------------------------------------------------------------------
   * DOM references
   * ------------------------------------------------------------------ */

  dom.postListEl      = document.getElementById('post-list');
  dom.editorEl        = document.getElementById('editor');
  dom.parentInput     = document.getElementById('parent_id');
  dom.replyingToEl    = document.getElementById('replying-to');
  dom.submitBtn       = document.getElementById('submit-post');
  dom.cancelReplyBtn  = document.getElementById('cancel-reply');
  dom.newPostFormEl   = document.querySelector('.new-post-form');
  dom.postSentinel    = document.getElementById('post-sentinel');
  dom.postLoadMoreBtn = document.getElementById('post-load-more');

  const {
    postListEl,
    editorEl,
    parentInput,
    replyingToEl,
    submitBtn,
    cancelReplyBtn,
    newPostFormEl,
    postSentinel,
    postLoadMoreBtn,
  } = dom;

  /* ------------------------------------------------------------------
   * Seen / unread tracking
   * ------------------------------------------------------------------ */

  seen.key = `thread_seen_${THREAD_ID}`;
  seen.set = new Set();

  // Load seen IDs from localStorage
  try {
    const raw = localStorage.getItem(seen.key);
    if (raw) {
      const arr = JSON.parse(raw);
      if (Array.isArray(arr)) {
        arr.forEach(id => {
          const n = Number(id);
          if (n) seen.set.add(n);
        });
      }
    }
  } catch (e) {
    // ignore parse errors, start with empty set
  }

  function saveSeen() {
    try {
      localStorage.setItem(seen.key, JSON.stringify([...seen.set]));
    } catch (e) {
      // localStorage might be full / blocked; ignore
    }
  }

  function ensureObserver() {
    if (seen.observer) return seen.observer;

    // posts currently "counting down" their 10s view time
    const pendingSeen = new Set();

    seen.observer = new IntersectionObserver(
      entries => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;

          const el = entry.target;
          const postId = Number(el.dataset.postId);
          if (!postId) return;

          if (seen.set.has(postId)) return;
          if (pendingSeen.has(postId)) return;

          pendingSeen.add(postId);

          setTimeout(() => {
            pendingSeen.delete(postId);

            if (!seen.set.has(postId)) {
              seen.set.add(postId);
              saveSeen();

              el.classList.remove('post--unread');
              el.classList.add('post--seen-fade');

              setTimeout(() => {
                el.classList.remove('post--seen-fade');
              }, 1200);
            }
          }, 10000);
        });
      },
      {
        threshold: 0.5 // ~50% visible counts as "seen"
      }
    );

    return seen.observer;
  }

  /* ------------------------------------------------------------------
   * State for replies / posting
   * ------------------------------------------------------------------ */

  let replyParentId = null;
  let isPosting = false;

  /* ------------------------------------------------------------------
   * Toast helpers
   * ------------------------------------------------------------------ */

  function ensureToastEl() {
    if (toast.el) return;

    toast.el = document.createElement('button');
    toast.el.className = 'thread-toast';
    toast.el.type = 'button';
    toast.el.style.display = 'none';

    toast.el.addEventListener('click', () => {
      if (!toast.lastNotifiedPostId || !postListEl) return;
      const target = postListEl.querySelector(
        `.post[data-post-id="${toast.lastNotifiedPostId}"]`
      );
      if (target) {
        target.scrollIntoView({
          behavior: 'smooth',
          block: 'center'
        });
      }
      hideToast();
    });

    document.body.appendChild(toast.el);
  }

  function showToastForPost(post) {
    ensureToastEl();
    toast.lastNotifiedPostId = post.id;

    const isReply = !!post.parent_id;
    const actionText = isReply ? 'has replied' : 'has commented';

    toast.el.textContent = `${post.username} ${actionText}`;
    toast.el.style.display = 'block';

    if (toast.timer) {
      clearTimeout(toast.timer);
    }
    toast.timer = setTimeout(hideToast, 10000);
  }

  function hideToast() {
    if (toast.el) {
      toast.el.style.display = 'none';
    }
    if (toast.timer) {
      clearTimeout(toast.timer);
      toast.timer = null;
    }
  }

  function maybeShowToastForNewPosts(added) {
    if (!added || !added.length) return;
    const others = added.filter(p =>
      Number(p.user_id) !== CURRENT_USER_ID &&
      Number(p.is_deleted) !== 1
    );
    if (!others.length) return;
    const latest = others[others.length - 1];
    showToastForPost(latest);
  }

  /* ------------------------------------------------------------------
   * Tree building
   * ------------------------------------------------------------------ */

  function buildTree(items) {
    const map = {};
    items.forEach(p => {
      p.children = [];
      map[p.id] = p;
    });

    const roots = [];
    items.forEach(p => {
      if (p.parent_id && map[p.parent_id]) {
        map[p.parent_id].children.push(p);
      } else {
        roots.push(p);
      }
    });

    return roots;
  }

  /* ------------------------------------------------------------------
   * Lazy rendering of roots
   * ------------------------------------------------------------------ */

  function updatePostLoadControls() {
    if (!postLoadMoreBtn || !postSentinel) return;

    if (lazy.hasMoreRoots) {
      postLoadMoreBtn.hidden = false;
      postSentinel.style.display = 'block';
    } else {
      postLoadMoreBtn.hidden = true;
      postSentinel.style.display = 'none';
    }
  }

  function renderNode(node, depth, isFirstChild = false) {
    if (!postListEl) return;
    if (depth > 5) depth = 5;

    const div = document.createElement('div');
    div.className = `post indent-${depth}`;
    div.dataset.postId = node.id;

    const recCount = Number(node.rec_count || 0);
    if (recCount > 0) {
      div.classList.add('post--rec');
    }

    const idNum = Number(node.id);
    if (!seen.set.has(idNum)) {
      div.classList.add('post--unread');
    }

    // ---- parent/child flags for L-shaped connector ----
    const hasParent = !!node.parent_id;
    if (hasParent) {
      div.classList.add('post--has-parent');
      if (isFirstChild) {
        div.classList.add('post--first-reply');
      }
    }

    // --- header row with username + date, avatar on the right ---
    const header = document.createElement('div');
    header.className = 'post-header';

    const meta = document.createElement('div');
    meta.className = 'post-meta';
    meta.textContent = `${node.username} • ${node.created_at}`;
    header.appendChild(meta);

    const avatarWrap = document.createElement('div');
    avatarWrap.className = 'post-avatar-wrap';

    const avatarImg = document.createElement('img');
    avatarImg.className = 'post-avatar';

    if (node.avatar_path) {
      avatarImg.src = '/' + String(node.avatar_path).replace(/^\/+/, '');
    } else {
      avatarImg.src = '/assets/img/default-avatar.png'; 
    }

    avatarImg.dataset.fullSrc = avatarImg.src;
    avatarImg.alt = `${node.username}'s avatar`;

    avatarWrap.appendChild(avatarImg);
    header.appendChild(avatarWrap);

    div.appendChild(header);

    // --- body ---
    const body = document.createElement('div');
    body.className = 'post-body';
    body.innerHTML = node.body_html;
    div.appendChild(body);

    // --- actions row ---
    const actions = document.createElement('div');
    actions.className = 'post-actions';

    // left side: Reply / Delete
    const left = document.createElement('div');
    left.className = 'post-actions__left';

    const replyBtn = document.createElement('button');
    replyBtn.type = 'button';
    replyBtn.textContent = 'Reply';
    replyBtn.addEventListener('click', () => setReplyTo(node));
    replyBtn.className = 'button-ghost post-reply';
    left.appendChild(replyBtn);

    if (IS_ADMIN) {
      const deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.textContent = 'Delete';
      deleteBtn.addEventListener('click', () => deletePost(node));
      deleteBtn.className = 'button-ghost post-delete';
      left.appendChild(deleteBtn);
    }

    actions.appendChild(left);

    // right side: "replied to" button (if reply) + Rec text control
    const right = document.createElement('div');
    right.className = 'post-actions__right';

    if (hasParent) {
      const repliedTo = document.createElement('button');
      repliedTo.type = 'button';
      repliedTo.className = 'post-replied-to';
      repliedTo.dataset.parentId = node.parent_id;
      repliedTo.textContent = 'replied to';
      right.appendChild(repliedTo);
      // thread-replied-to.js will wire up the click behavior
    }

    const recBtn = document.createElement('button');
    recBtn.type = 'button';
    recBtn.className = 'post-rec-text'; // style like plain text in CSS
    recBtn.textContent = recLabel(recCount);
    right.appendChild(recBtn);

    actions.appendChild(right);

    // Rec toggle behavior
    recBtn.addEventListener('click', () => toggleRec(node, div, recBtn));

    div.appendChild(actions);
    postListEl.appendChild(div);

    // Observe to track "seen"
    const obs = ensureObserver();
    obs.observe(div);

    // Replies: oldest → newest under their parent
    if (node.children && node.children.length) {
      node.children.sort(
        (a, b) => new Date(a.created_at) - new Date(b.created_at)
      );
      node.children.forEach((child, idx) =>
        renderNode(child, depth + 1, idx === 0)
      );
    }
  }

  function renderNextChunk() {
    if (!postListEl) return;
    if (!lazy.hasMoreRoots || lazy.isRenderingChunk) return;
    if (!lazy.rootTree.length) return;

    lazy.isRenderingChunk = true;

    const start = lazy.renderedRootCount;
    const end = Math.min(start + lazy.ROOTS_PER_CHUNK, lazy.rootTree.length);

    if (start >= end) {
      lazy.hasMoreRoots = false;
      updatePostLoadControls();
      lazy.isRenderingChunk = false;
      return;
    }

    for (let i = start; i < end; i++) {
      const rootNode = lazy.rootTree[i];
      renderNode(rootNode, 0);
    }

    lazy.renderedRootCount = end;
    lazy.hasMoreRoots = lazy.renderedRootCount < lazy.rootTree.length;
    updatePostLoadControls();

    lazy.isRenderingChunk = false;
  }

  /* ------------------------------------------------------------------
   * Main render: rebuild tree, then lazy-render chunks
   * ------------------------------------------------------------------ */

  function renderPosts() {
    if (!postListEl) return;

    // Only show non-deleted posts
    const visiblePosts = state.posts.filter(p => Number(p.is_deleted) !== 1);

    const tree = buildTree(visiblePosts);

    // Root posts: newest first
    tree.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

    lazy.rootTree = tree;
    lazy.renderedRootCount = 0;
    lazy.hasMoreRoots = lazy.rootTree.length > 0;

    postListEl.innerHTML = '';

    updatePostLoadControls();
    renderNextChunk(); // render first chunk immediately
  }

  /* ------------------------------------------------------------------
   * Reply + posting
   * ------------------------------------------------------------------ */

  function setReplyTo(post) {
    replyParentId = post.id;
    parentInput.value = post.id;
    replyingToEl.textContent = `Replying to ${post.username}…`;
    replyingToEl.style.display = 'block';
    cancelReplyBtn.style.display = 'inline-block';

    if (newPostFormEl) {
      newPostFormEl.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    } else if (editorEl) {
      editorEl.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }

    if (editorEl) {
      try {
        editorEl.focus?.();
        const range = document.createRange();
        range.selectNodeContents(editorEl);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      } catch (e) {
        // ignore
      }
    }
  }

  function clearReplyTo() {
    replyParentId = null;
    parentInput.value = '';
    replyingToEl.textContent = '';
    replyingToEl.style.display = 'none';
    cancelReplyBtn.style.display = 'none';
  }

  async function submitPost() {
    if (!editorEl || isPosting) return;

    const html = editorEl.innerHTML.trim();
    const parentId = parentInput.value || null;
    if (!html) return;

    isPosting = true;
    try {
      const newPost = await submitPostAPI({
        threadId: state.THREAD_ID,
        parentId,
        bodyHtml: html
      });

      if (newPost && newPost.id) {
        renderPosts();

        if (postListEl) {
          const newEl = postListEl.querySelector(
            `.post[data-post-id="${newPost.id}"]`
          );
          if (newEl) {
            newEl.scrollIntoView({
              behavior: 'smooth',
              block: 'center'
            });
          }
        }
      }

      editorEl.innerHTML = '';
      clearReplyTo();
    } catch (err) {
      console.error('Submit failed', err);
    } finally {
      isPosting = false;
    }
  }

  async function deletePost(post) {
    if (!post || !post.id) return;

    try {
      await deletePostAPI(post.id);
      renderPosts();
    } catch (err) {
      console.error('Delete failed (exception)', err);
    }
  }

  if (submitBtn) {
    submitBtn.addEventListener('click', submitPost);
  }
  if (cancelReplyBtn) {
    cancelReplyBtn.addEventListener('click', clearReplyTo);
  }

  /* ------------------------------------------------------------------
   * Infinite scroll for posts (lazy chunks)
   * ------------------------------------------------------------------ */

  if (postSentinel && 'IntersectionObserver' in window) {
    const postsObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting && !lazy.isRenderingChunk) {
          if (lazy.hasMoreRoots) {
            // Still have unrendered posts already in memory
            renderNextChunk();
          } else if (state.offset > 0) {
            // No more in-memory posts; fetch the next batch from server
            fetchPosts({ initial: true }).then(({ added }) => {
              if (added && added.length) {
                renderPosts();
              }
            });
          }
        }
      });
    }, { rootMargin: '200px' });

    postsObserver.observe(postSentinel);
  }

  if (postLoadMoreBtn) {
    postLoadMoreBtn.addEventListener('click', () => {
      if (lazy.hasMoreRoots) {
        renderNextChunk();
      } else if (state.offset > 0) {
        fetchPosts({ initial: true }).then(({ added }) => {
          if (added && added.length) {
            renderPosts();
          }
        });
      }
    });
  }

  /* ------------------------------------------------------------------
   * Init – initial load + light polling
   * ------------------------------------------------------------------ */

  (async function init() {
    const { added } = await fetchPosts({ initial: true });
    renderPosts();

    // Every 5 seconds; adjust if you want even lighter
    setInterval(() => {
      // Only poll when tab is visible to save resources
      if (document.visibilityState === 'visible') {
        fetchPosts({ initial: false }).then(({ added }) => {
          if (added && added.length) {
            renderPosts();
            maybeShowToastForNewPosts(added);
          }
        });
      }
    }, 10000);
  })();
});
