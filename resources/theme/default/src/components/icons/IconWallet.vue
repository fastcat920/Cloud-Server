<template>
  <TablerIconWallet :size="size" :stroke-width="strokeWidth" :class="customClass" />
</template>

<script>
import { IconWallet } from './IconHelper';

export default {
  name: 'IconWallet',
  components: {
    TablerIconWallet: IconWallet
  },
  props: {
    size: {
      type: [Number, String],
      default: 24
    },
    strokeWidth: {
      type: [Number, String],
      default: 2
    },
    customClass: {
      type: String,
      default: ''
    }
  }
};
</script>
