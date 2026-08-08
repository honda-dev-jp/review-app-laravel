@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl',
    'labelledBy',
    'restoreFocusTo' => null,
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth];
@endphp

<div
    x-data="{
        show: @js($show),
        // モーダルを開いた要素を保持し、閉じた後のフォーカス復帰に使用する。
        triggerElement: null,

        // bodyやモーダル内部を起動元として誤保存せず、利用可能な起動元だけを保持して開く。
        open(trigger) {
            if (this.show) {
                return
            }

            this.triggerElement =
                trigger instanceof HTMLElement
                && trigger !== document.body
                && ! this.$el.contains(trigger)
                    ? trigger
                    : null

            this.show = true
            this.activate()
        },

        // モーダルを閉じ、実際の起動元または初期表示時の指定復帰先へフォーカスを戻す。
        close() {
            if (! this.show) {
                return
            }

            this.show = false
            document.body.classList.remove('overflow-y-hidden')

            const fallbackTrigger = @js($restoreFocusTo)
                ? document.getElementById(@js($restoreFocusTo))
                : null

            // 実際の起動元を優先し、使用できない場合に指定された復帰先を評価する。
            const focusCandidates = [this.triggerElement, fallbackTrigger]
            this.triggerElement = null

            this.$nextTick(() => {
                const trigger = focusCandidates.find(candidate =>
                    this.canRestoreFocus(candidate)
                )

                trigger?.focus()
            })
        },

        // DOM上に存在し、表示・操作できる要素だけをフォーカス復帰先として許可する。
        canRestoreFocus(element) {
            return element instanceof HTMLElement
                && element.isConnected
                && ! element.matches(':disabled')
                && element.getClientRects().length > 0
                && getComputedStyle(element).visibility !== 'hidden'
        },

        // 背景スクロールを止め、DOM反映後にモーダル内へ初期フォーカスを移す。
        activate() {
            document.body.classList.add('overflow-y-hidden')

            this.$nextTick(() => {
                const target = @js($attributes->has('focusable'))
                    ? this.firstFocusable()
                    : null

                ;(target || this.$refs.dialogPanel)?.focus()
            })
        },

        // バリデーションエラー後など、読み込み時点で開いているモーダルにも初期処理を適用する。
        initialize() {
            if (this.show) {
                this.activate()
            }
        },

        // ダイアログ内で実際にTab移動できる、表示・操作可能な要素だけを抽出する。
        focusables() {
            // All focusable element types...
            let selector = 'a[href], button, input:not([type=\'hidden\']), textarea, select, summary, [tabindex]'
            return [...this.$refs.dialogPanel.querySelectorAll(selector)]
                // All visible and non-disabled elements...
                .filter(el => {
                    const tabindex = el.getAttribute('tabindex')

                    return ! el.matches(':disabled')
                        && (tabindex === null || Number(tabindex) >= 0)
                        && ! el.closest('[inert]')
                        && el.getClientRects().length > 0
                        && getComputedStyle(el).visibility !== 'hidden'
                })
        },
        // 初期フォーカス先として、抽出した最初の候補を返す。
        firstFocusable() { return this.focusables()[0] || null },

        // 通常の移動はブラウザへ任せ、端または候補外の場合だけダイアログ内を循環させる。
        trapTab(event) {
            const focusables = this.focusables()

            if (focusables.length === 0) {
                event.preventDefault()
                this.$refs.dialogPanel?.focus()
                return
            }

            const currentIndex = focusables.indexOf(document.activeElement)

            if (event.shiftKey && currentIndex <= 0) {
                event.preventDefault()
                focusables[focusables.length - 1].focus()
                return
            }

            if (
                ! event.shiftKey
                && (currentIndex === -1 || currentIndex === focusables.length - 1)
            ) {
                event.preventDefault()
                focusables[0].focus()
            }
        },
    }"
    x-init="initialize()"
    x-on:open-modal.window="$event.detail === @js($name) ? open($event.target) : null"
    x-on:close-modal.window="$event.detail === @js($name) ? close() : null"
    x-on:close.stop="close()"
    x-on:keydown.escape.window="show && close()"
    x-on:keydown.tab.window="show && trapTab($event)"
    x-show="show"
    class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
    style="display: {{ $show ? 'block' : 'none' }};"
>
    <div
        x-show="show"
        class="fixed inset-0 transform transition-all"
        x-on:click="close()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
    </div>

    <div
        x-ref="dialogPanel"
        x-show="show"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $labelledBy }}"
        tabindex="-1"
        class="mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        {{ $slot }}
    </div>
</div>
