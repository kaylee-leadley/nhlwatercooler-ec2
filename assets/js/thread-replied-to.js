// assets/js/thread-replied-to.js
document.addEventListener('DOMContentLoaded', () => {
  const postList = document.getElementById('post-list');
  if (!postList) return;

  function clearHighlights() {
    document
      .querySelectorAll('.post--highlight-parent')
      .forEach(el => el.classList.remove('post--highlight-parent'));
  }

  postList.addEventListener('click', (e) => {
    const trigger = e.target.closest('.post-replied-to');
    if (!trigger) return;

    e.preventDefault();

    const parentId = trigger.dataset.parentId;
    if (!parentId) return;

    const parentEl = postList.querySelector(
      `.post[data-post-id="${parentId}"]`
    );
    if (!parentEl) return;

    clearHighlights();

    parentEl.classList.add('post--highlight-parent');
    parentEl.scrollIntoView({
      behavior: 'smooth',
      block: 'center'
    });

    // Let the highlight linger a bit, then fade
    setTimeout(() => {
      parentEl.classList.remove('post--highlight-parent');
    }, 5000);
  });
});
