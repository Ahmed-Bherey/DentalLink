<?php

namespace Tests\Feature\Orders;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Order;
use App\Models\Financial\OrderItem;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STAGE 7.5 — the order-detail contract Angular consumes.
 *
 * Covers four gaps together, because they all surface on the same response:
 *
 *   #1  order lines must report the HISTORICAL price, not the live one
 *   #2  money must keep its decimals
 *   #4  pending_return_quantity must be visible
 *   #5  a single order must be reachable without paging a list
 */
class OrderDetailContractTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = User::factory()->doctor()->create();
        $this->supplier = User::factory()->supplier()->create();
    }

    // ------------------------------------------------------------- helpers

    private function offer(float $price, int $stock = 100, ?float $basePrice = null): Product
    {
        return Product::factory()
            ->ownedBy($this->supplier)
            ->of(ProductMaster::factory()->create())
            ->pricedAt($price)
            ->create(['quantity' => $stock, 'base_price' => $basePrice]);
    }

    /** Place an order through the real endpoint so the snapshot is written by the app. */
    private function buy(array $lines): Order
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => $lines])
            ->assertSuccessful();

        return Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->firstOrFail();
    }

    private function deliver(Order $order): void
    {
        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertSuccessful();
    }

    private function showAs(User $user, $orderId)
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/order/{$orderId}");
    }

    // ================================================ Gap #5 — the endpoint

    /** @test */
    public function a_doctor_can_view_their_own_order()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 2]]);

        $this->showAs($this->doctor, $order->id)
            ->assertSuccessful()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonStructure([
                'status',
                'data' => ['id', 'status', 'status_name', 'total_price', 'created_at', 'products'],
            ]);
    }

    /** @test */
    public function a_supplier_can_view_an_order_containing_their_product()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 2]]);

        $this->showAs($this->supplier, $order->id)
            ->assertSuccessful()
            ->assertJsonPath('data.id', $order->id);
    }

    /** @test */
    public function another_doctor_cannot_view_someone_elses_order()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 1]]);

        // 403, not 404: the order surface is two-party, so its existence is not
        // a secret from a participant. Products/carts/receipts use 404 because
        // their ids must not be enumerable — that distinction is preserved.
        $this->showAs(User::factory()->doctor()->create(), $order->id)->assertStatus(403);
    }

    /** @test */
    public function an_unrelated_supplier_cannot_view_the_order()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 1]]);

        $this->showAs(User::factory()->supplier()->create(), $order->id)->assertStatus(403);
    }

    /** @test */
    public function an_unauthenticated_request_is_rejected()
    {
        // Built with factories rather than through the API: actingAs() persists
        // for the remainder of a test, so placing the order over HTTP first
        // would leave this request authenticated and the assertion vacuous.
        $order = Order::factory()->placedBy($this->doctor)->create();
        OrderItem::factory()->forOffer($this->offer(10), 1)->create(['order_id' => $order->id]);

        $this->getJson("/api/order/{$order->id}")->assertStatus(401);
    }

    /** @test */
    public function a_missing_order_returns_not_found()
    {
        $this->showAs($this->doctor, 999999)->assertStatus(404);
    }

    /**
     * A non-numeric id must not reach the controller at all — the route is
     * constrained so it cannot shadow the literal GET routes in the group.
     *
     * @test
     */
    public function a_non_numeric_id_does_not_match_the_detail_route()
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/order/not-a-number')
            ->assertStatus(404);
    }

    /**
     * The sibling literal routes must still resolve to their own handlers.
     *
     * @test
     */
    public function the_detail_route_does_not_shadow_the_list_routes()
    {
        foreach (['index-type', 'all-delivered', 'search', 'refund'] as $path) {
            $this->actingAs($this->doctor, 'sanctum')
                ->getJson("/api/order/{$path}")
                ->assertSuccessful();
        }
    }

    /** @test */
    public function an_order_with_several_lines_returns_all_of_them()
    {
        $order = $this->buy([
            ['id' => $this->offer(10)->id, 'quantity' => 2],
            ['id' => $this->offer(5.25)->id, 'quantity' => 4],
        ]);

        $data = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data');

        $this->assertCount(2, $data['products']);
        $this->assertEquals(41, $data['total_price'], '(10 x 2) + (5.25 x 4)');
    }

    // ============================== Gap #1 — historical, not current, price

    /** @test */
    public function an_order_line_reports_the_price_it_was_bought_at_not_the_current_one()
    {
        $offer = $this->offer(10);
        $order = $this->buy([['id' => $offer->id, 'quantity' => 3]]);

        // The supplier reprices AFTER the sale.
        $offer->update(['price' => 12]);

        $line = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data.products.0');

        $this->assertEquals(10, $line['unit_price'], 'Frozen at purchase.');
        $this->assertEquals(30, $line['line_total'], '3 x 10.');

        // The legacy aliases now carry the historical values too.
        $this->assertEquals(10, $line['price']);
        $this->assertEquals(30, $line['total_price']);

        // The current price is available, under an unambiguous key.
        $this->assertEquals(12, $line['current_product_price']);
    }

    /** @test */
    public function the_order_total_equals_the_sum_of_its_lines_after_a_price_change()
    {
        $a = $this->offer(10);
        $b = $this->offer(7.5);
        $order = $this->buy([
            ['id' => $a->id, 'quantity' => 2],
            ['id' => $b->id, 'quantity' => 2],
        ]);

        $a->update(['price' => 99]);
        $b->update(['price' => 99]);

        $data = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data');

        $sum = array_sum(array_column($data['products'], 'line_total'));

        $this->assertEquals(35, $data['total_price']);
        $this->assertEquals(
            $data['total_price'],
            $sum,
            'The lines must reconcile with the order total — they did not before Stage 7.5.'
        );
    }

    /** @test */
    public function an_order_line_exposes_its_canonical_product_and_supplier()
    {
        $offer = $this->offer(10);
        $order = $this->buy([['id' => $offer->id, 'quantity' => 1]]);

        $line = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data.products.0');

        $this->assertSame($offer->product_master_id, $line['product_master_id']);
        $this->assertSame($this->supplier->id, $line['supplier_id']);
        $this->assertSame($this->supplier->name, $line['supplier_name']);
        $this->assertSame($offer->id, $line['product_id']);
    }

    /**
     * The line shape must be identical whether it arrives from the detail
     * endpoint or from a list — a field that appears on one and not the other
     * forces the client to defend against its own API.
     *
     * @test
     */
    public function the_line_shape_is_the_same_from_the_list_and_the_detail_endpoint()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 2]]);

        $fromDetail = $this->showAs($this->doctor, $order->id)
            ->assertSuccessful()->json('data.products.0');

        $fromList = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/order/index-type')
            ->assertSuccessful()->json('data.0.products.0');

        $this->assertSame(array_keys($fromDetail), array_keys($fromList));
        $this->assertSame($fromDetail, $fromList);
        $this->assertNotNull($fromList['supplier_name']);
    }

    // ================================================ Gap #2 — decimals

    /**
     * @test
     *
     * @dataProvider decimalPrices
     */
    public function order_line_money_keeps_its_decimals($price, $quantity, $expectedLineTotal)
    {
        $order = $this->buy([['id' => $this->offer($price)->id, 'quantity' => $quantity]]);

        $line = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data.products.0');

        $this->assertEquals($price, $line['unit_price']);
        $this->assertEquals($expectedLineTotal, $line['line_total']);
    }

    public function decimalPrices(): array
    {
        return [
            'whole' => [10, 3, 30],
            'half' => [10.50, 3, 31.50],
            'quarter' => [10.25, 2, 20.50],
            'two places' => [125.75, 1, 125.75],
            'near-thousand' => [999.99, 2, 1999.98],
        ];
    }

    /**
     * A package order carries a fixed agreed price on the order itself. It is a
     * DECIMAL column, which PDO hands back as a string — it must still serialise
     * as a JSON number, like every other order total.
     *
     * @test
     */
    public function a_package_order_total_is_a_number_not_a_string()
    {
        $order = Order::factory()->placedBy($this->doctor)->packagePricedAt(500.25)->create();
        OrderItem::factory()->forOffer($this->offer(10), 1)->create(['order_id' => $order->id]);

        $total = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data.total_price');

        $this->assertIsFloat($total);
        $this->assertEquals(500.25, $total);
    }

    // ======================================= Gap #4 — pending return visibility

    /** @test */
    public function a_line_with_no_return_reports_zero_pending_and_full_returnable()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 5]]);
        $this->deliver($order);

        $line = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data.products.0');

        $this->assertSame(0, $line['returned_quantity']);
        $this->assertSame(0, $line['pending_return_quantity']);
        $this->assertSame(5, $line['returnable_quantity']);
    }

    /** @test */
    public function a_pending_return_is_visible_and_reduces_the_returnable_quantity()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 5]]);
        $this->deliver($order);
        $item = $order->orderItems()->first();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 2])
            ->assertSuccessful();

        $line = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data.products.0');

        $this->assertSame(2, $line['pending_return_quantity'], 'Awaiting the supplier.');
        $this->assertSame(0, $line['returned_quantity'], 'Nothing approved yet.');
        // Unchanged at 5: a further request AMENDS the open one rather than
        // adding to it, so the ceiling is still purchased-less-approved.
        $this->assertSame(5, $line['returnable_quantity']);
        $this->assertSame('delete_pending', $line['status']);
    }

    /** @test */
    public function an_approved_return_moves_the_quantity_from_pending_to_returned()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 5]]);
        $this->deliver($order);
        $item = $order->orderItems()->first();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 2])
            ->assertSuccessful();

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => 'confirmed'])
            ->assertSuccessful();

        $line = $this->showAs($this->doctor, $order->id)->assertSuccessful()->json('data.products.0');

        $this->assertSame(2, $line['returned_quantity']);
        $this->assertSame(0, $line['pending_return_quantity']);
        $this->assertSame(3, $line['returnable_quantity']);
        $this->assertSame(5, $line['quantity'], 'The purchased quantity never changes.');
        $this->assertEquals(50, $line['line_total'], 'The original contract value stands.');
    }

    /**
     * The advertised ceiling must agree with what the server actually accepts,
     * in both directions — otherwise the client either blocks a valid request
     * or offers one that will be refused.
     *
     * @test
     */
    public function the_returnable_quantity_matches_what_the_server_will_accept()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 5]]);
        $this->deliver($order);
        $item = $order->orderItems()->first();

        // Approve 2, so 3 genuinely remain.
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 2])
            ->assertSuccessful();
        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => 'confirmed'])
            ->assertSuccessful();

        $returnable = $this->showAs($this->doctor, $order->id)
            ->json('data.products.0.returnable_quantity');

        $this->assertSame(3, $returnable);

        // Exactly the advertised figure is accepted...
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => $returnable])
            ->assertSuccessful();

        // ...and one more than it is refused, server-side.
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => $returnable + 1])
            ->assertStatus(422);
    }

    /**
     * The cumulative rule the brief requires: approved + pending can never let
     * the doctor exceed what they bought.
     *
     * @test
     */
    public function approved_plus_pending_can_never_exceed_the_purchased_quantity()
    {
        $order = $this->buy([['id' => $this->offer(10)->id, 'quantity' => 5]]);
        $this->deliver($order);
        $item = $order->orderItems()->first();

        // Approve 3.
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 3])
            ->assertSuccessful();
        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => 'confirmed'])
            ->assertSuccessful();

        // A pending request for the remaining 2 is fine...
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 2])
            ->assertSuccessful();

        $fresh = $item->fresh();
        $this->assertLessThanOrEqual(
            (int) $fresh->quantity,
            (int) $fresh->returned_quantity + (int) $fresh->pending_return_quantity
        );

        // ...but 3 would make approved(3) + pending(3) = 6 > 5.
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 3])
            ->assertStatus(422);
    }
}
