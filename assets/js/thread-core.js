// assets/js/thread-core.js
// Shared namespace + core state / helpers

window.GAMEDAY_THREAD = window.GAMEDAY_THREAD || {};

(function (ns) {
  // Core state for this thread page
  ns.state = {
    THREAD_ID: 0,
    IS_ADMIN: false,
    CURRENT_USER_ID: 0,

    // Posts + pagination
    posts: [],
    lastPostId: 0,  // highest seen post id (for polling)
    offset: 0,      // for initial paging (limit/offset)
    PAGE_SIZE: 100, // how many posts to load per server page
  };

  // DOM references will be filled by thread.js
  ns.dom = {
    postListEl: null,
    editorEl: null,
    parentInput: null,
    replyingToEl: null,
    submitBtn: null,
    cancelReplyBtn: null,
    newPostFormEl: null,
    postSentinel: null,
    postLoadMoreBtn: null,
  };

  // Lazy-root rendering state (for big threads)
  ns.lazy = {
    ROOTS_PER_CHUNK: 30,
    rootTree: [],
    renderedRootCount: 0,
    hasMoreRoots: false,
    isRenderingChunk: false,
  };

  // Seen / unread tracking
  ns.seen = {
    key: null,
    set: new Set(),
    observer: null,
  };

  // Toast notification
  ns.toast = {
    el: null,
    timer: null,
    lastNotifiedPostId: null,
  };

  // Rec label helper
  ns.recLabel = function recLabel(count) {
    count = parseInt(count, 10) || 0;
    if (count === 0) return 'Rec';
    if (count === 1) return '1 Rec';
    return `${count} Recs`;
  };
})(window.GAMEDAY_THREAD);
