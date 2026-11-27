import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Shop\ProductReviewController::report
 * @see app/Http/Controllers/Shop/ProductReviewController.php:63
 * @route '/shop/reviews/{review}/report'
 */
export const report = (args: { review: number | { id: number } } | [review: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: report.url(args, options),
    method: 'post',
})

report.definition = {
    methods: ["post"],
    url: '/shop/reviews/{review}/report',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Shop\ProductReviewController::report
 * @see app/Http/Controllers/Shop/ProductReviewController.php:63
 * @route '/shop/reviews/{review}/report'
 */
report.url = (args: { review: number | { id: number } } | [review: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { review: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { review: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    review: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        review: typeof args.review === 'object'
                ? args.review.id
                : args.review,
                }

    return report.definition.url
            .replace('{review}', parsedArgs.review.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\ProductReviewController::report
 * @see app/Http/Controllers/Shop/ProductReviewController.php:63
 * @route '/shop/reviews/{review}/report'
 */
report.post = (args: { review: number | { id: number } } | [review: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: report.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Shop\ProductReviewController::report
 * @see app/Http/Controllers/Shop/ProductReviewController.php:63
 * @route '/shop/reviews/{review}/report'
 */
    const reportForm = (args: { review: number | { id: number } } | [review: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: report.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Shop\ProductReviewController::report
 * @see app/Http/Controllers/Shop/ProductReviewController.php:63
 * @route '/shop/reviews/{review}/report'
 */
        reportForm.post = (args: { review: number | { id: number } } | [review: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: report.url(args, options),
            method: 'post',
        })
    
    report.form = reportForm
const reviews = {
    report: Object.assign(report, report),
}

export default reviews