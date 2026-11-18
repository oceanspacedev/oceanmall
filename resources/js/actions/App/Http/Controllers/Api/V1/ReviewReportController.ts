import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\ReviewReportController::__invoke
 * @see app/Http/Controllers/Api/V1/ReviewReportController.php:23
 * @route '/api/v1/catalog/reviews/{review}/report'
 */
const ReviewReportController = (args: { review: string | number } | [review: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ReviewReportController.url(args, options),
    method: 'post',
})

ReviewReportController.definition = {
    methods: ["post"],
    url: '/api/v1/catalog/reviews/{review}/report',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\ReviewReportController::__invoke
 * @see app/Http/Controllers/Api/V1/ReviewReportController.php:23
 * @route '/api/v1/catalog/reviews/{review}/report'
 */
ReviewReportController.url = (args: { review: string | number } | [review: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { review: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    review: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        review: args.review,
                }

    return ReviewReportController.definition.url
            .replace('{review}', parsedArgs.review.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ReviewReportController::__invoke
 * @see app/Http/Controllers/Api/V1/ReviewReportController.php:23
 * @route '/api/v1/catalog/reviews/{review}/report'
 */
ReviewReportController.post = (args: { review: string | number } | [review: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ReviewReportController.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Api\V1\ReviewReportController::__invoke
 * @see app/Http/Controllers/Api/V1/ReviewReportController.php:23
 * @route '/api/v1/catalog/reviews/{review}/report'
 */
    const ReviewReportControllerForm = (args: { review: string | number } | [review: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: ReviewReportController.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Api\V1\ReviewReportController::__invoke
 * @see app/Http/Controllers/Api/V1/ReviewReportController.php:23
 * @route '/api/v1/catalog/reviews/{review}/report'
 */
        ReviewReportControllerForm.post = (args: { review: string | number } | [review: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: ReviewReportController.url(args, options),
            method: 'post',
        })
    
    ReviewReportController.form = ReviewReportControllerForm
export default ReviewReportController