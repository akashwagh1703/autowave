<?php

namespace App\Domain\Chatbot\Flows;

use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotContent;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Support\Interactive;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Services\OnlineShop;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ordering in the chat: products (by category when there are many; up to 10 with photos as swipeable
 * cards, else a list) → quantity → cart → pickup or
 * delivery (+ address) → confirm. Same rules as the website cart (OnlineShop: server prices, stock,
 * minimum order, delivery fee, auto-confirm). The cart lives in the session (`order`), so it is cleared
 * when the chat goes quiet for a while. Coupons are only on the website for now.
 *
 * Options: `aw.or.cat.{category}.{page}`, `aw.or.p.{product}`, `aw.or.add.{product}`, `aw.or.qty.{product}.{n}`,
 * `aw.or.cart`, `aw.or.clear`, `aw.or.checkout`, `aw.or.ful.{pickup|delivery}`, `aw.or.saved` (saved address),
 * `aw.or.ok`, `aw.or.no`.
 */
class OrderFlow extends ChatFlow
{
    public function __construct(
        ChatbotContent $content,
        private readonly OnlineShop $shop,
        private readonly CommerceSettings $settings,
        private readonly TenantContext $context,
    ) {
        parent::__construct($content);
    }

    public function key(): string
    {
        return 'or';
    }

    /** @return array{body: string, interactive: ?array<string, mixed>} */
    public function start(ChatbotSession $session): array
    {
        return $this->catalogue($session, null, 0);
    }

    public function handle(ChatbotSession $session, Conversation $conversation, array $args): ?array
    {
        $id = self::int($args[1] ?? null);

        return match ($args[0] ?? null) {
            'cat' => $this->catalogue($session, $args[1] ?? 'all', self::int($args[2] ?? null)),
            'p' => $this->product($session, $id),
            'add' => $this->quantity($session, $id),
            'qty' => $this->add($session, $id, self::int($args[2] ?? null)),
            'cart' => $this->cart($session),
            'clear' => $this->clear($session),
            'checkout' => $this->checkout($session, $conversation),
            'ful' => $this->fulfilment($session, $conversation, $args[1] ?? ''),
            'saved' => $this->savedAddress($session, $conversation),
            'ok' => $this->place($session, $conversation),
            'no' => $this->cancelled(__('No problem, your order was not placed. Your cart is kept for a little while.')),
            default => null,
        };
    }

    public function typed(ChatbotSession $session, Conversation $conversation, string $what, string $text): array
    {
        if ($what !== 'address') {
            return parent::typed($session, $conversation, $what, $text);
        }

        $address = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if (mb_strlen($address) < 8) {
            return $this->reply(__('Please type the full delivery address, with house number, street and area.'), null);
        }

        $session->stopWaiting();
        $this->remember($session, ['fulfilment' => 'delivery', 'address' => Str::limit($address, 500, '')]);

        return $this->confirm($session, $conversation);
    }

    /**
     * @param  string|null  $category  null to decide, `all`, `0` for products without a category, or a category id
     */
    private function catalogue(ChatbotSession $session, ?string $category, int $page): array
    {
        $products = $this->products();

        if ($products->isEmpty()) {
            return $this->failed(__('Online ordering is not available right now.'), 'order');
        }

        $categories = $products->map(fn (Product $product) => $product->category)->filter()->unique('id')->values();
        $intro = $this->cartLine($session).__('What would you like to order? Tap an item to see it and add it to your cart.')
            .$this->websiteLine('#products', __('See everything with photos on our website:'));

        if ($category === null && $products->count() > Interactive::MAX_ROWS && $categories->count() > 1) {
            $rows = $categories->map(fn ($item) => [
                'id' => $this->id('cat', $item->id, 0),
                'title' => $item->name,
                'description' => trans_choice(':count item|:count items', $products->where('category.id', $item->id)->count()),
            ])->values()->all();

            if ($products->whereNull('category')->isNotEmpty()) {
                $rows[] = ['id' => $this->id('cat', 0, 0), 'title' => __('Other items')];
            }

            return $this->reply($intro, Interactive::list($this->browseLabel(), array_slice($rows, 0, Interactive::MAX_ROWS), $this->title()));
        }

        $category ??= 'all';

        if ($category !== 'all') {
            $products = $category === '0' ? $products->whereNull('category') : $products->where('category.id', self::int($category));
        }

        $cards = $page === 0 ? $this->productCards($products) : null;

        if ($cards) {
            return $this->reply($this->cartLine($session).__('What would you like to order? Swipe through the items and tap *Add to cart*.')
                .$this->websiteLine('#products', __('See everything on our website:')), $cards);
        }

        $rows = $products->map(fn (Product $product) => [
            'id' => $this->id('p', $product->id),
            'title' => $product->name,
            'description' => $this->content->money($product->price).($this->orderable($product) ? '' : ' · '.__('Not available now')),
        ])->values()->all();

        return $this->reply($intro, Interactive::list(
            $this->browseLabel(),
            $this->page($rows, $page, fn (int $next) => $this->id('cat', $category, $next), __('More items')),
            $this->title(),
        ));
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>|null
     */
    private function productCards(Collection $products): ?array
    {
        return $this->cards($products->map(fn (Product $product) => [
            'id' => $this->id('p', $product->id),
            'title' => $product->name,
            'text' => '*'.$product->name.'*'."\n".$this->content->money($product->price)
                .($this->orderable($product) ? '' : ' · '.__('Not available now'))
                .(filled($product->description) ? "\n".Str::limit(Str::squish((string) $product->description), 90) : ''),
            'image' => $product->image,
            'buttons' => [
                ['id' => $this->id('add', $product->id), 'title' => __('Add to cart')],
                ['id' => $this->id('p', $product->id), 'title' => __('Details')],
            ],
        ])->values()->all());
    }

    private function product(ChatbotSession $session, int $id): array
    {
        $product = $this->products()->firstWhere('id', $id);

        if (! $product) {
            return $this->catalogue($session, null, 0);
        }

        $body = '*'.$product->name.'*'."\n".$this->content->money($product->price)
            .($product->compare_at_price !== null && (float) $product->compare_at_price > (float) $product->price ? ' ~'.$this->content->money($product->compare_at_price).'~' : '')
            .(filled($product->description) ? "\n\n".Str::limit((string) $product->description, 500) : '')
            .($this->orderable($product) ? '' : "\n\n".__('Sorry, this is not available right now.'));
        $count = $this->count($session);

        return $this->reply($body, Interactive::buttons(array_values(array_filter([
            $this->orderable($product) ? ['id' => $this->id('add', $product->id), 'title' => __('Add to cart')] : null,
            ['id' => self::PREFIX.'order', 'title' => __('Back to list')],
            $count > 0 ? ['id' => $this->id('cart'), 'title' => __('View cart (:count)', ['count' => $count])] : ['id' => self::PREFIX.'menu', 'title' => __('Main menu')],
        ])), null, $product->image));
    }

    private function quantity(ChatbotSession $session, int $id): array
    {
        $product = $this->products()->firstWhere('id', $id);

        if (! $product || ! $this->orderable($product)) {
            return $this->product($session, $id);
        }

        $max = min(Interactive::MAX_ROWS, (int) config('commerce.limits.max_quantity'), $product->available() ?? PHP_INT_MAX);

        if ($max <= 1) {
            return $this->add($session, $id, 1);
        }

        $rows = [];

        for ($quantity = 1; $quantity <= $max; $quantity++) {
            $rows[] = ['id' => $this->id('qty', $product->id, $quantity), 'title' => (string) $quantity, 'description' => $this->content->money(bcmul((string) $product->price, (string) $quantity, 2))];
        }

        return $this->reply(__('How many *:name*?', ['name' => $product->name]), Interactive::list(__('Choose'), $rows, $this->title()));
    }

    private function add(ChatbotSession $session, int $id, int $quantity): array
    {
        $product = $this->products()->firstWhere('id', $id);

        if (! $product || $quantity < 1) {
            return $this->catalogue($session, null, 0);
        }

        $cart = $this->items($session);
        $limits = config('commerce.limits');

        if (! isset($cart[$id]) && count($cart) >= (int) $limits['items_per_order']) {
            return $this->reply(__('An order can have up to :max different items.', ['max' => $limits['items_per_order']]), $this->cartButtons());
        }

        $cart[$id] = min(($cart[$id] ?? 0) + $quantity, (int) $limits['max_quantity']);
        $this->remember($session, ['cart' => $cart]);
        $quote = $this->shop->quote($this->lines($cart), null);

        return $this->reply(
            __('Added :quantity × :name. 🛒', ['quantity' => $quantity, 'name' => $product->name])."\n"
            .__('Your cart: :items · :total', ['items' => trans_choice(':count item|:count items', array_sum($cart)), 'total' => $this->content->money($quote['subtotal'])]),
            Interactive::buttons([
                ['id' => $this->id('checkout'), 'title' => __('Checkout')],
                ['id' => self::PREFIX.'order', 'title' => __('Add more')],
                ['id' => $this->id('cart'), 'title' => __('View cart')],
            ]),
        );
    }

    private function cart(ChatbotSession $session): array
    {
        $cart = $this->items($session);

        if ($cart === []) {
            return $this->emptyCart();
        }

        $quote = $this->shop->quote($this->lines($cart), null);
        $lines = collect($quote['lines'])->map(fn (array $line) => '• '.$line['quantity'].' × '.($line['name'] ?? __('Item')).' — '.$this->content->money($line['line_total']).($line['issue'] ? "\n  ⚠️ ".$line['issue'] : ''))->implode("\n");

        return $this->reply(
            __('*Your cart*')."\n\n".$lines."\n\n".__('Subtotal: :amount', ['amount' => $this->content->money($quote['subtotal'])]),
            Interactive::buttons([
                ['id' => $this->id('checkout'), 'title' => __('Checkout')],
                ['id' => self::PREFIX.'order', 'title' => __('Add more')],
                ['id' => $this->id('clear'), 'title' => __('Empty cart')],
            ]),
        );
    }

    private function clear(ChatbotSession $session): array
    {
        $session->put('order', null);

        return $this->emptyCart();
    }

    private function checkout(ChatbotSession $session, Conversation $conversation): array
    {
        $cart = $this->items($session);

        if ($cart === []) {
            return $this->emptyCart();
        }

        $quote = $this->shop->quote($this->lines($cart), null);

        if (! $quote['can_checkout']) {
            return $this->reply(implode("\n", array_map(fn (string $issue) => '⚠️ '.$issue, $quote['issues'])), $this->cartButtons());
        }

        $methods = $this->settings->onlineFulfilment();

        if (count($methods) === 1) {
            return $this->fulfilment($session, $conversation, $methods[0]);
        }

        $online = $this->settings->online();
        $fee = $this->settings->deliveryFeeFor($quote['subtotal']);

        return $this->reply(
            __('Pickup or delivery?')
            .("\n".__('Delivery: :fee', ['fee' => bccomp($fee, '0', 2) === 0 ? __('free') : $this->content->money($fee)]))
            .($online['free_delivery_over'] !== null && bccomp($fee, '0', 2) > 0 ? ' '.__('(free over :amount)', ['amount' => $this->content->money($online['free_delivery_over'])]) : ''),
            Interactive::buttons([
                ['id' => $this->id('ful', 'pickup'), 'title' => __('Pickup')],
                ['id' => $this->id('ful', 'delivery'), 'title' => __('Delivery')],
                ['id' => $this->id('cart'), 'title' => __('View cart')],
            ]),
        );
    }

    private function fulfilment(ChatbotSession $session, Conversation $conversation, string $method): array
    {
        if (! in_array($method, $this->settings->onlineFulfilment(), true)) {
            return $this->checkout($session, $conversation);
        }

        if ($method === 'pickup') {
            $this->remember($session, ['fulfilment' => 'pickup', 'address' => null]);

            return $this->confirm($session, $conversation);
        }

        $saved = $conversation->customer?->address;
        $session->await($this->key(), 'address', [$this->key(), 'ok']);
        $note = $this->settings->online()['delivery_note'];

        return $this->reply(
            __('Please type the delivery address, with house number, street and area.')
            .($note ? "\n\n".$note : '')
            .($saved ? "\n\n".__('Or tap *Use saved address*: :address', ['address' => Str::limit($saved, 120)]) : ''),
            $saved ? Interactive::buttons([
                ['id' => $this->id('saved'), 'title' => __('Use saved address')],
                ['id' => $this->id('cart'), 'title' => __('View cart')],
            ]) : null,
        );
    }

    private function savedAddress(ChatbotSession $session, Conversation $conversation): array
    {
        $saved = $conversation->customer?->address;

        if (blank($saved)) {
            return $this->fulfilment($session, $conversation, 'delivery');
        }

        $session->stopWaiting();
        $this->remember($session, ['fulfilment' => 'delivery', 'address' => Str::limit((string) $saved, 500, '')]);

        return $this->confirm($session, $conversation);
    }

    private function confirm(ChatbotSession $session, Conversation $conversation): array
    {
        $cart = $this->items($session);
        $order = $session->get('order', []);
        $fulfilment = $order['fulfilment'] ?? null;

        if ($cart === []) {
            return $this->emptyCart();
        }

        if ($fulfilment === null || ($fulfilment === 'delivery' && blank($order['address'] ?? null))) {
            return $this->checkout($session, $conversation);
        }

        $name = $this->name($session, $conversation);

        if ($name === null) {
            return $this->askName($session, ['ok']);
        }

        $quote = $this->shop->quote($this->lines($cart), $fulfilment);

        if (! $quote['can_checkout']) {
            return $this->reply(implode("\n", array_map(fn (string $issue) => '⚠️ '.$issue, $quote['issues'])), $this->cartButtons());
        }

        $items = collect($quote['lines'])->map(fn (array $line) => '• '.$line['quantity'].' × '.$line['name'].' — '.$this->content->money($line['line_total']))->implode("\n");
        $lines = array_filter([
            __('*Please check your order*'),
            '',
            $items,
            '',
            bccomp($quote['delivery_fee'], '0', 2) > 0 ? __('Delivery: :amount', ['amount' => $this->content->money($quote['delivery_fee'])]) : null,
            '*'.__('Total: :amount', ['amount' => $this->content->money($quote['total'])]).'*',
            $fulfilment === 'delivery' ? '🛵 '.__('Delivery to: :address', ['address' => $order['address']]) : '🛍️ '.__('Pickup from :business', ['business' => $this->content->name()]),
            '🙍 '.$name,
            '',
            $this->settings->online()['auto_confirm'] ? null : __('We will confirm it here shortly.'),
        ], fn ($line) => $line !== null);

        return $this->reply(trim(implode("\n", $lines)), Interactive::buttons([
            ['id' => $this->id('ok'), 'title' => __('Place order')],
            ['id' => $this->id('cart'), 'title' => __('Change')],
            ['id' => $this->id('no'), 'title' => __('Cancel')],
        ]));
    }

    private function place(ChatbotSession $session, Conversation $conversation): array
    {
        $cart = $this->items($session);
        $order = $session->get('order', []);
        $name = $this->name($session, $conversation);

        if ($cart === [] && $this->alreadyPlaced($session)) {
            return $this->reply(__('Your order is already placed. 👍'), $this->menuButtons());
        }

        if ($cart === [] || $name === null || ($order['fulfilment'] ?? null) === null) {
            return $this->confirm($session, $conversation);
        }

        try {
            $placed = $this->shop->place([
                'name' => $name,
                'phone' => $this->phone($conversation),
                'email' => $this->email($conversation),
                'fulfilment' => $order['fulfilment'],
                'delivery_address' => $order['address'] ?? null,
                'items' => $this->lines($cart),
            ], 'whatsapp');
        } catch (ValidationException $exception) {
            return $this->reply('⚠️ '.self::firstError($exception), $this->cartButtons());
        }

        $session->put('order', null);
        $session->put('placed', $placed->id);
        $this->linkCustomer($conversation, $placed->customer_id);

        $how = $placed->fulfilment === 'delivery' ? __('We will deliver it to you.') : __('We will tell you here when it is ready for pickup.');
        $body = $placed->status === OrderStatus::Pending
            ? __('🙏 Thank you, :name! We have your order *:number* (total :total). We will confirm it here shortly.', ['name' => $name, 'number' => $placed->number, 'total' => $this->content->money($placed->total)])
            : __('✅ Order *:number* is confirmed! Total :total.', ['number' => $placed->number, 'total' => $this->content->money($placed->total)])."\n".$how;

        return $this->reply($body, Interactive::buttons([
            ['id' => self::PREFIX.'menu', 'title' => __('Main menu')],
            ['id' => self::PREFIX.'info', 'title' => __('Timings & location')],
        ]));
    }

    private function alreadyPlaced(ChatbotSession $session): bool
    {
        return $session->get('placed') !== null;
    }

    /** @return Collection<int, Product> */
    private function products(): Collection
    {
        return Product::query()->active()->ordered()->with(['category', 'image'])->limit(300)->get()
            ->sortBy(fn (Product $product) => [$product->category === null ? 1 : 0, $product->category?->sort_order ?? 0, $product->category?->name ?? '', $product->sort_order, $product->name])
            ->values();
    }

    private function orderable(Product $product): bool
    {
        return $product->is_available && $product->isInStock();
    }

    /** @return array<int, int> product id => quantity */
    private function items(ChatbotSession $session): array
    {
        $cart = $session->get('order', [])['cart'] ?? [];

        return is_array($cart) ? array_map('intval', $cart) : [];
    }

    /**
     * @param  array<int, int>  $cart
     * @return list<array{product_id: int, quantity: int}>
     */
    private function lines(array $cart): array
    {
        return array_map(fn (int $id, int $quantity) => ['product_id' => $id, 'quantity' => $quantity], array_keys($cart), $cart);
    }

    private function count(ChatbotSession $session): int
    {
        return array_sum($this->items($session));
    }

    /** @param  array<string, mixed>  $values */
    private function remember(ChatbotSession $session, array $values): void
    {
        $session->put('order', array_filter([...$session->get('order', []), ...$values], fn ($value) => $value !== null && $value !== []) ?: null);
        $session->put('placed', null);
    }

    private function cartLine(ChatbotSession $session): string
    {
        $count = $this->count($session);

        return $count > 0 ? '🛒 '.trans_choice(':count item in your cart|:count items in your cart', $count)."\n\n" : '';
    }

    private function emptyCart(): array
    {
        return $this->reply(__('Your cart is empty.'), Interactive::buttons([
            ['id' => self::PREFIX.'order', 'title' => $this->browseLabel()],
            ['id' => self::PREFIX.'menu', 'title' => __('Main menu')],
        ]));
    }

    /** @return array<string, mixed> */
    private function cartButtons(): array
    {
        return Interactive::buttons([
            ['id' => $this->id('cart'), 'title' => __('View cart')],
            ['id' => self::PREFIX.'order', 'title' => __('Add more')],
            ['id' => self::PREFIX.'human', 'title' => __('Talk to us')],
        ]);
    }

    private function browseLabel(): string
    {
        return $this->context->hasEngine('food') ? __('See menu') : __('See products');
    }

    private function title(): string
    {
        return $this->context->hasEngine('food') ? __('Our menu') : __('Order online');
    }
}
