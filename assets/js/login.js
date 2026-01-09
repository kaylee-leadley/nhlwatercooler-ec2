// assets/js/login.js

document.addEventListener('DOMContentLoaded', () => {
  const userInput = document.getElementById('login-username');
  if (userInput) {
    userInput.focus();
    userInput.select();
  }
});
