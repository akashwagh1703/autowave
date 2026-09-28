import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ProductForm from '@/modules/products/ProductForm';

export default function Create({ categories, defaultCategoryId, defaultLowStock, foodTypes }) {
    const form = useForm({
        name: '',
        product_category_id: defaultCategoryId,
        sku: '',
        price: '',
        compare_at_price: '',
        description: '',
        is_active: true,
        is_available: true,
        food_type: null,
        track_stock: false,
        opening_stock: '',
        low_stock_threshold: '',
        image: null,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/products');
    };

    return (
        <AppLayout title="Add product">
            <Button component={Link} href="/products" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Products
            </Button>
            <PageHeader title="Add product" description="Something you sell: its price, stock and how it appears on your website." />
            <Card variant="outlined" className="max-w-3xl">
                <CardContent>
                    <ProductForm
                        form={form}
                        onSubmit={submit}
                        submitLabel="Add product"
                        cancelHref="/products"
                        categories={categories}
                        defaultLowStock={defaultLowStock}
                        foodTypes={foodTypes}
                        creating
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}
