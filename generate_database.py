import csv
import random
from datetime import datetime, timedelta

def generate_dataset(filename, num_records=1000):
    headers = [
        'date', 'camp_id', 'occupancy', 'capacity',
        'food_stock', 'food_consumption_rate',
        'medicine_stock', 'medicine_consumption_rate',
        'active_volunteers', 'pending_tasks',
        'days_to_food_shortage', 'days_to_medicine_shortage', 'volunteer_shortage'
    ]

    start_date = datetime(2025, 1, 1)

    with open(filename, mode='w', newline='') as file:
        writer = csv.writer(file)
        writer.writerow(headers)

        for i in range(num_records):
            # Random date within the last year
            current_date = start_date + timedelta(days=random.randint(0, 365))
            camp_id = random.randint(1, 5)

            # Base variables
            capacity = random.randint(300, 1000)
            occupancy = random.randint(100, capacity)

            # Consumption rates per person per day
            food_consumption_rate = round(random.uniform(1.5, 2.5), 2)  # packets per person
            medicine_consumption_rate = round(random.uniform(0.1, 0.3), 2) # kits per person

            # Current stocks
            food_stock = random.randint(0, 5000)
            medicine_stock = random.randint(0, 500)

            active_volunteers = random.randint(5, 50)
            pending_tasks = random.randint(0, 100)

            # Target variables calculations
            daily_food_needed = occupancy * food_consumption_rate
            days_to_food_shortage = round(food_stock / daily_food_needed) if daily_food_needed > 0 else 999

            daily_medicine_needed = occupancy * medicine_consumption_rate
            days_to_medicine_shortage = round(medicine_stock / daily_medicine_needed) if daily_medicine_needed > 0 else 999

            # Heuristic for volunteer shortage: 1 volunteer per 20 people and 1 volunteer per 3 pending tasks
            ideal_volunteers = (occupancy // 20) + (pending_tasks // 3)
            volunteer_shortage = max(0, ideal_volunteers - active_volunteers)

            writer.writerow([
                current_date.strftime('%Y-%m-%d'),
                camp_id,
                occupancy,
                capacity,
                food_stock,
                food_consumption_rate,
                medicine_stock,
                medicine_consumption_rate,
                active_volunteers,
                pending_tasks,
                days_to_food_shortage,
                days_to_medicine_shortage,
                volunteer_shortage
            ])

if __name__ == '__main__':
    generate_dataset('resource_demand_dataset.csv')
    print("Dataset generated successfully as 'resource_demand_dataset.csv'.")
