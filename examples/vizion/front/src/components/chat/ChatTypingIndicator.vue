<template>
  <div class="typing-indicator">
    <span class="dot" />
    <span class="dot" />
    <span class="dot" />
  </div>
</template>

<style lang="scss" scoped>
.typing-indicator {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0.75rem 1rem;
}

.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background-color: $surface-400;
  animation: bounce 1.2s infinite ease-in-out;

  &:nth-child(1) {
    animation-delay: 0ms;
  }

  &:nth-child(2) {
    animation-delay: 150ms;
  }

  &:nth-child(3) {
    animation-delay: 300ms;
  }
}

@keyframes bounce {
  0%,
  80%,
  100% {
    transform: translateY(0);
    opacity: 0.4;
  }
  40% {
    transform: translateY(-6px);
    opacity: 1;
  }
}
</style>
